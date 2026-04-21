<?php

namespace App\Http\Controllers\Panel;

use App\Exports\WebinarStudents;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Traits\VideoDemoTrait;
use App\Mixins\RegistrationPackage\UserPackage;
use App\Models\BundleWebinar;
use App\Models\Category;
use App\Models\Faq;
use App\Models\File;
use App\Models\Gift;
use App\Models\Prerequisite;
use App\Models\Quiz;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Session;
use App\Models\SubscribeUse;
use App\Models\Tag;
use App\Models\TextLesson;
use App\Models\Ticket;
use App\Models\Translation\WebinarTranslation;
use App\Models\WebinarChapter;
use App\Models\WebinarChapterItem;
use App\Models\WebinarExtraDescription;
use App\User;
use App\Models\Webinar;
use App\Models\WebinarPartnerTeacher;
use App\Models\WebinarFilterOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Validator;

class WebinarController extends Controller
{
    use VideoDemoTrait;

    public function index(Request $request)
    {
        $this->authorize("panel_webinars_lists");

        $user = auth()->user();

        if ($user->isUser()) {
            abort(404);
        }

        $query = Webinar::where(function ($query) use ($user) {
            if ($user->isTeacher()) {
                $query->where('teacher_id', $user->id);
            } elseif ($user->isOrganization()) {
                $query->where('creator_id', $user->id);
            }
        });

        $data = $this->makeMyClassAndInvitationsData($query, $user, $request);
        $data['pageTitle'] = trans('webinars.webinars_list_page_title');

        return view(getTemplate() . '.panel.webinar.index', $data);
    }


    public function invitations(Request $request)
    {
        $this->authorize("panel_webinars_invited_lists");

        $user = auth()->user();

        $invitedWebinarIds = WebinarPartnerTeacher::where('teacher_id', $user->id)->pluck('webinar_id')->toArray();

        $query = Webinar::query();

        if ($user->isUser()) {
            abort(404);
        }

        $query->whereIn('id', $invitedWebinarIds);

        $data = $this->makeMyClassAndInvitationsData($query, $user, $request);
        $data['pageTitle'] = trans('panel.invited_classes');

        return view(getTemplate() . '.panel.webinar.index', $data);
    }

    public function organizationClasses(Request $request)
    {
        $this->authorize("panel_webinars_organization_classes");

        $user = auth()->user();

        if (!empty($user->organ_id)) {
            $query = Webinar::where('creator_id', $user->organ_id)
                ->where('status', 'active');

            $query = $this->organizationClassesFilters($query, $request);

            $webinars = $query
                ->orderBy('created_at', 'desc')
                ->orderBy('updated_at', 'desc')
                ->paginate(10);

            $data = [
                'pageTitle' => trans('panel.organization_classes'),
                'webinars' => $webinars,
            ];

            return view(getTemplate() . '.panel.webinar.organization_classes', $data);
        }

        abort(404);
    }

    private function organizationClassesFilters($query, $request)
    {
        $from = $request->get('from', null);
        $to = $request->get('to', null);
        $type = $request->get('type', null);
        $sort = $request->get('sort', null);
        $free = $request->get('free', null);

        $query = fromAndToDateFilter($from, $to, $query, 'start_date');

        if (!empty($type) and $type != 'all') {
            $query->where('type', $type);
        }

        if (!empty($sort) and $sort != 'all') {
            if ($sort == 'expensive') {
                $query->orderBy('price', 'desc');
            }

            if ($sort == 'inexpensive') {
                $query->orderBy('price', 'asc');
            }

            if ($sort == 'bestsellers') {
                $query->whereHas('sales')
                    ->with('sales')
                    ->get()
                    ->sortBy(function ($qu) {
                        return $qu->sales->count();
                    });
            }

            if ($sort == 'best_rates') {
                $query->with([
                    'reviews' => function ($query) {
                        $query->where('status', 'active');
                    }
                ])->get()
                    ->sortBy(function ($qu) {
                        return $qu->reviews->avg('rates');
                    });
            }
        }

        if (!empty($free) and $free == 'on') {
            $query->where(function ($qu) {
                $qu->whereNull('price')
                    ->orWhere('price', '<', '0');
            });
        }

        return $query;
    }

    private function makeMyClassAndInvitationsData($query, $user, $request)
    {
        $webinarHours = deepClone($query)->sum('duration');

        $onlyNotConducted = $request->get('not_conducted');
        if (!empty($onlyNotConducted)) {
            $query->where('status', 'active')
                ->where('start_date', '>', time());
        }

        $query->with([
            'reviews' => function ($query) {
                $query->where('status', 'active');
            },
            'category',
            'teacher'
        ])->orderBy('updated_at', 'desc');

        $webinarsCount = $query->count();

        $webinars = $query->paginate(10);

        $webinarSales = Sale::where('seller_id', $user->id)
            ->where('type', 'webinar')
            ->whereNotNull('webinar_id')
            ->whereNull('refund_at')
            ->with('webinar')
            ->get();

        $webinarSalesAmount = 0;
        $courseSalesAmount = 0;
        foreach ($webinarSales as $webinarSale) {
            if (!empty($webinarSale->webinar) and $webinarSale->webinar->type == 'webinar') {
                $webinarSalesAmount += $webinarSale->amount;
            } else {
                $courseSalesAmount += $webinarSale->amount;
            }
        }

        foreach ($webinars as $webinar) {
            $giftsIds = Gift::query()->where('webinar_id', $webinar->id)
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('date');
                    $query->orWhere('date', '<', time());
                })
                ->whereHas('sale')
                ->pluck('id')
                ->toArray();

            $sales = Sale::query()
                ->where(function ($query) use ($webinar, $giftsIds) {
                    $query->where('webinar_id', $webinar->id);
                    $query->orWhereIn('gift_id', $giftsIds);
                })
                ->whereNull('refund_at')
                ->get();

            $webinar->sales = $sales;
        }

        return [
            'webinars' => $webinars,
            'webinarsCount' => $webinarsCount,
            'webinarSalesAmount' => $webinarSalesAmount,
            'courseSalesAmount' => $courseSalesAmount,
            'webinarHours' => $webinarHours,
        ];
    }

    function array_replace_key($search, $replace, array $subject)
    {
        $updatedArray = [];

        foreach ($subject as $key => $value) {
            if (!is_array($value) && $key == $search) {
                $updatedArray = array_merge($updatedArray, [$replace => $value]);

                continue;
            }

            $updatedArray = array_merge($updatedArray, [$key => $value]);
        }

        return $updatedArray;
    }

    public function create(Request $request)
    {
        $this->authorize("panel_webinars_create");

        $user = auth()->user();

        if (!$user->isTeacher() and !$user->isOrganization()) {
            abort(404);
        }

        $userPackage = new UserPackage();
        $userCoursesCountLimited = $userPackage->checkPackageLimit('courses_count');

        if ($userCoursesCountLimited) {
            session()->put('registration_package_limited', $userCoursesCountLimited);

            return redirect()->back();
        }

        $categories = Category::where('parent_id', null)
            ->with('subCategories')
            ->get();

        $teachers = null;
        $isOrganization = $user->isOrganization();

        if ($isOrganization) {
            $teachers = User::where('role_name', Role::$teacher)
                ->where('organ_id', $user->id)->get();
        }

        $stepCount = empty(getGeneralOptionsSettings('direct_publication_of_courses')) ? 8 : 7;

        $data = [
            'pageTitle' => trans('webinars.new_page_title'),
            'teachers' => $teachers,
            'categories' => $categories,
            'isOrganization' => $isOrganization,
            'currentStep' => 1,
            'stepCount' => $stepCount,
            'userLanguages' => getUserLanguagesLists(),
        ];

        return view(getTemplate() . '.panel.webinar.create', $data);
    }

    public function store(Request $request)
    {
        $this->authorize("panel_webinars_create");

        $user = auth()->user();

        if (!$user->isTeacher() and !$user->isOrganization()) {
            abort(404);
        }

        $userPackage = new UserPackage();
        $userCoursesCountLimited = $userPackage->checkPackageLimit('courses_count');

        if ($userCoursesCountLimited) {
            session()->put('registration_package_limited', $userCoursesCountLimited);

            return redirect()->back();
        }

        $currentStep = $request->get('current_step', 1);

        $rules = [
            'type' => 'required|in:webinar,course,text_lesson',
            'title' => 'required|max:255',
            'thumbnail' => 'required',
            'image_cover' => 'required',
            'description' => 'required',
        ];

        $this->validate($request, $rules);

        $data = $request->all();
        $data = $this->handleVideoDemoData($request, $data, "course_demo_" . time());

        $webinar = Webinar::create([
            'teacher_id' => $user->isTeacher() ? $user->id : (!empty($data['teacher_id']) ? $data['teacher_id'] : $user->id),
            'creator_id' => $user->id,
            'slug' => Webinar::makeSlug($data['title']),
            'type' => $data['type'],
            'private' => (!empty($data['private']) and $data['private'] == 'on') ? true : false,
            'thumbnail' => $data['thumbnail'],
            'image_cover' => $data['image_cover'],
            'video_demo' => $data['video_demo'],
            'video_demo_source' => $data['video_demo'] ? $data['video_demo_source'] : null,
            'status' => ((!empty($data['draft']) and $data['draft'] == 1) or (!empty($data['get_next']) and $data['get_next'] == 1)) ? Webinar::$isDraft : Webinar::$pending,
            'created_at' => time(),
        ]);

        if ($webinar) {
            WebinarTranslation::updateOrCreate([
                'webinar_id' => $webinar->id,
                'locale' => mb_strtolower($data['locale']),
            ], [
                'title' => $data['title'],
                'description' => $data['description'],
                'seo_description' => $data['seo_description'],
            ]);
        }


        $notifyOptions = [
            '[u.name]' => $user->full_name,
            '[item_title]' => $webinar->title,
            '[content_type]' => trans('admin/main.course'),
        ];
        sendNotification("new_item_created", $notifyOptions, 1);

        $url = '/panel/webinars';
        if ($data['get_next'] == 1) {
            $url = '/panel/webinars/' . $webinar->id . '/step/2';
        }

        return redirect($url);
    }

    public function edit(Request $request, $id, $step = 1)
    {
        $this->authorize("panel_webinars_create");

        $user = auth()->user();
        $isOrganization = $user->isOrganization();

        if (!$user->isTeacher() and !$user->isOrganization()) {
            abort(404);
        }
        $locale = $request->get('locale', app()->getLocale());

        $stepCount = empty(getGeneralOptionsSettings('direct_publication_of_courses')) ? 8 : 7;

        $data = [
            'pageTitle' => trans('webinars.new_page_title_step', ['step' => $step]),
            'currentStep' => $step,
            'isOrganization' => $isOrganization,
            'userLanguages' => getUserLanguagesLists(),
            'locale' => mb_strtolower($locale),
            'defaultLocale' => getDefaultLocale(),
            'stepCount' => $stepCount
        ];

        $query = Webinar::where('id', $id)
            ->where(function ($query) use ($user) {
                $query->where(function ($query) use ($user) {
                    $query->where('creator_id', $user->id)
                        ->orWhere('teacher_id', $user->id);
                });

                $query->orWhereHas('webinarPartnerTeacher', function ($query) use ($user) {

                });
            });

        if ($step == '1') {
            $data['teachers'] = $user->getOrganizationTeachers()->get();
        } elseif ($step == 2) {
            $query->with([
                'category' => function ($query) {
                    $query->with(['filters' => function ($query) {
                        $query->with('options');
                    }]);
                },
                'filterOptions',
                'webinarPartnerTeacher' => function ($query) {
                    $query->with(['teacher' => function ($query) {
                        $query->select('id', 'full_name');
                    }]);
                },
                'tags',
            ]);

            $categories = Category::where('parent_id', null)
                ->with('subCategories')
                ->get();

            $data['categories'] = $categories;
        } elseif ($step == 3) {
            $query->with([
                'tickets' => function ($query) {
                    $query->orderBy('order', 'asc');
                },
            ]);
        } elseif ($step == 4) {
            $query->with([
                'chapters' => function ($query) {
                    $query->orderBy('order', 'asc');
                    $query->with([
                        'chapterItems' => function ($query) {
                            $query->orderBy('order', 'asc');

                            $query->with([
                                'quiz' => function ($query) {
                                    $query->with([
                                        'quizQuestions' => function ($query) {
                                            $query->orderBy('order', 'asc');
                                        }
                                    ]);
                                }
                            ]);
                        }
                    ]);
                },
            ]);
        } elseif ($step == 5) {
            $query->with([
                'prerequisites' => function ($query) {
                    $query->with(['prerequisiteWebinar' => function ($qu) {
                        $qu->with(['teacher' => function ($q) {
                            $q->select('id', 'full_name');
                        }]);
                    }])->orderBy('order', 'asc');
                }
            ]);
        } elseif ($step == 6) {
            $query->with([
                'faqs' => function ($query) {
                    $query->orderBy('order', 'asc');
                },
                'webinarExtraDescription' => function ($query) {
                    $query->orderBy('order', 'asc');
                }
            ]);
        } elseif ($step == 7) {
            $query->with([
                'quizzes',
                'chapters' => function ($query) {
                    $query->where('status', WebinarChapter::$chapterActive)
                        ->orderBy('order', 'asc');
                }
            ]);

            $teacherQuizzes = Quiz::where('webinar_id', null)
                ->where('creator_id', $user->id)
                ->whereNull('webinar_id')
                ->get();

            $data['teacherQuizzes'] = $teacherQuizzes;
        }


        $webinar = $query->first();

        if (empty($webinar)) {
            abort(404);
        }

        $data['webinar'] = $webinar;

        $data['pageTitle'] = trans('public.edit') . ' ' . $webinar->title;

        $definedLanguage = [];
        if ($webinar->translations) {
            $definedLanguage = $webinar->translations->pluck('locale')->toArray();
        }

        $data['definedLanguage'] = $definedLanguage;

        if ($step == 2) {
            $data['webinarTags'] = $webinar->tags->pluck('title')->toArray();

            $webinarCategoryFilters = !empty($webinar->category) ? $webinar->category->filters : [];

            if (empty($webinar->category) and !empty($request->old('category_id'))) {
                $category = Category::where('id', $request->old('category_id'))->first();

                if (!empty($category)) {
                    $webinarCategoryFilters = $category->filters;
                }
            }

            $data['webinarCategoryFilters'] = $webinarCategoryFilters;
        }

        if ($step == 3) {
            $data['sumTicketsCapacities'] = $webinar->tickets->sum('capacity');
        }


        return view(getTemplate() . '.panel.webinar.create', $data);
    }

    public function update(Request $request, $id)
    {
        $this->authorize("panel_webinars_create");

        $user = auth()->user();

        if (!$user->isTeacher() and !$user->isOrganization()) {
            abort(404);
        }

        $rules = [];
        $data = $request->all();
        $currentStep = $data['current_step'];
        $getStep = $data['get_step'];
        $getNextStep = (!empty($data['get_next']) and $data['get_next'] == 1);
        $isDraft = (!empty($data['draft']) and $data['draft'] == 1);

        $webinar = Webinar::where('id', $id)
            ->where(function ($query) use ($user) {
                $query->where(function ($query) use ($user) {
                    $query->where('creator_id', $user->id)
                        ->orWhere('teacher_id', $user->id);
                });

                $query->orWhereHas('webinarPartnerTeacher', function ($query) use ($user) {
                    $query->where('teacher_id', $user->id);
                });
            })->first();

        if (empty($webinar)) {
            abort(404);
        }

        if ($currentStep == 1) {
            $rules = [
                'type' => 'required|in:webinar,course,text_lesson',
                'title' => 'required|max:255',
                'thumbnail' => 'required',
                'image_cover' => 'required',
                'description' => 'required',
            ];
        }

        if ($currentStep == 2) {
            $rules = [
                'category_id' => 'required',
                'duration' => 'required|numeric',
                'partners' => 'required_if:partner_instructor,on',
                'capacity' => 'nullable|numeric|min:0'
            ];

            if ($webinar->isWebinar()) {
                $rules['start_date'] = 'required|date';
            }
        }

        if ($currentStep == 3) {
            $rules = [
                'price' => 'nullable|numeric|min:0',
            ];
        }

        $webinarRulesRequired = false;
        $directPublicationOfCourses = !empty(getGeneralOptionsSettings('direct_publication_of_courses'));

        if (!$directPublicationOfCourses and (($currentStep == 8 and !$getNextStep and !$isDraft) or (!$getNextStep and !$isDraft))) {
            $webinarRulesRequired = empty($data['rules']);
        }

        $this->validate($request, $rules);

        $status = ($isDraft or $webinarRulesRequired) ? Webinar::$isDraft : Webinar::$pending;

        if ($directPublicationOfCourses and !$getNextStep and !$isDraft) {
            $status = Webinar::$active;
        }

        $data['status'] = $status;
        $data['updated_at'] = time();

        if ($currentStep == 1) {
            $data['private'] = (!empty($data['private']) and $data['private'] == 'on');

            // Video Demo
            $data = $this->handleVideoDemoData($request, $data, "course_demo_" . time());
        }

        if ($currentStep == 2) {

            // Check Capacity
            $userPackage = new UserPackage($webinar->creator);
            $userCoursesCapacityLimited = $userPackage->checkPackageLimit('courses_capacity', $data['capacity']);

            if ($userCoursesCapacityLimited) {
                session()->put('registration_package_limited', $userCoursesCapacityLimited);

                return redirect()->back()->withInput($data);
            }
            // .\ Check Capacity

            if ($webinar->isWebinar()) {
                if (empty($data['timezone']) or !getFeaturesSettings('timezone_in_create_webinar')) {
                    $data['timezone'] = getTimezone();
                }

                $startDate = convertTimeToUTCzone($data['start_date'], $data['timezone']);

                $data['start_date'] = $startDate->getTimestamp();
            } elseif ($webinar->type != 'webinar') {
                // For courses and text_lessons, preserve existing start_date instead of overwriting
                // Only update if a new start_date is explicitly provided
                if (isset($data['start_date']) and !empty($data['start_date'])) {
                    // Process the date if provided for non-webinar types
                    if (empty($data['timezone']) or !getFeaturesSettings('timezone_in_create_webinar')) {
                        $data['timezone'] = getTimezone();
                    }
                    $startDate = convertTimeToUTCzone($data['start_date'], $data['timezone']);
                    $data['start_date'] = $startDate->getTimestamp();
                } else {
                    // Preserve existing start_date if not provided in request
                    $data['start_date'] = $webinar->start_date;
                }
            }

            $data['forum'] = !empty($data['forum']) ? true : false;
            $data['support'] = !empty($data['support']) ? true : false;
            $data['certificate'] = !empty($data['certificate']) ? true : false;
            $data['downloadable'] = !empty($data['downloadable']) ? true : false;
            $data['partner_instructor'] = !empty($data['partner_instructor']) ? true : false;

            if (empty($data['partner_instructor'])) {
                WebinarPartnerTeacher::where('webinar_id', $webinar->id)->delete();
                unset($data['partners']);
            }

            if ($data['category_id'] !== $webinar->category_id) {
                WebinarFilterOption::where('webinar_id', $webinar->id)->delete();
            }
        }

        if ($currentStep == 3) {
            $data['subscribe'] = !empty($data['subscribe']) ? true : false;
            $data['price'] = !empty($data['price']) ? convertPriceToDefaultCurrency($data['price']) : null;
            $data['organization_price'] = !empty($data['organization_price']) ? convertPriceToDefaultCurrency($data['organization_price']) : null;
        }

        $filters = $request->get('filters', null);
        if (!empty($filters) and is_array($filters)) {
            WebinarFilterOption::where('webinar_id', $webinar->id)->delete();
            foreach ($filters as $filter) {
                WebinarFilterOption::create([
                    'webinar_id' => $webinar->id,
                    'filter_option_id' => $filter
                ]);
            }
        }

        if (!empty($request->get('tags'))) {
            $tags = explode(',', $request->get('tags'));
            Tag::where('webinar_id', $webinar->id)->delete();

            foreach ($tags as $tag) {
                Tag::create([
                    'webinar_id' => $webinar->id,
                    'title' => $tag,
                ]);
            }
        }

        if (!empty($request->get('partner_instructor')) and !empty($request->get('partners'))) {
            WebinarPartnerTeacher::where('webinar_id', $webinar->id)->delete();

            foreach ($request->get('partners') as $partnerId) {
                WebinarPartnerTeacher::create([
                    'webinar_id' => $webinar->id,
                    'teacher_id' => $partnerId,
                ]);
            }
        }

        if ($webinar and $currentStep == 1) {
            WebinarTranslation::updateOrCreate([
                'webinar_id' => $webinar->id,
                'locale' => mb_strtolower($data['locale']),
            ], [
                'title' => $data['title'],
                'description' => $data['description'],
                'seo_description' => $data['seo_description'],
            ]);
        }

        unset($data['_token'],
            $data['current_step'],
            $data['draft'],
            $data['get_next'],
            $data['partners'],
            $data['tags'],
            $data['filters'],
            $data['ajax'],
            $data['title'],
            $data['description'],
            $data['seo_description'],
        );

        if (empty($data['teacher_id']) and $user->isOrganization() and $webinar->creator_id == $user->id) {
            $data['teacher_id'] = $user->id;
        }

        $webinar->update($data);

        $stepCount = empty(getGeneralOptionsSettings('direct_publication_of_courses')) ? 8 : 7;

        $url = '/panel/webinars';
        if ($getNextStep) {
            $nextStep = (!empty($getStep) and $getStep > 0) ? $getStep : $currentStep + 1;

            $url = '/panel/webinars/' . $webinar->id . '/step/' . (($nextStep <= $stepCount) ? $nextStep : $stepCount);
        }

        if ($webinarRulesRequired) {
            $url = '/panel/webinars/' . $webinar->id . '/step/8';

            return redirect($url)->withErrors(['rules' => trans('validation.required', ['attribute' => 'rules'])]);
        }

        if ($status != Webinar::$active and !$getNextStep and !$isDraft and !$webinarRulesRequired) {
            sendNotification('course_created', ['[c.title]' => $webinar->title], $user->id);

            $notifyOptions = [
                '[u.name]' => $user->full_name,
                '[item_title]' => $webinar->title,
                '[content_type]' => trans('admin/main.course'),
            ];
            sendNotification("content_review_request", $notifyOptions, 1);
        }

        return redirect($url);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorize("panel_webinars_delete");

        $user = auth()->user();

        if (!canDeleteContentDirectly()) {
            if ($request->ajax()) {
                return response()->json([], 422);
            } else {
                $toastData = [
                    'title' => trans('public.request_failed'),
                    'msg' => trans('update.it_is_not_possible_to_delete_the_content_directly'),
                    'status' => 'error'
                ];
                return redirect()->back()->with(['toast' => $toastData]);
            }
        }


        if (!$user->isTeacher() and !$user->isOrganization()) {
            abort(404);
        }

        $webinar = Webinar::where('id', $id)
            ->where('creator_id', $user->id)
            ->first();

        if (!$webinar) {
            abort(404);
        }

        $webinar->delete();

        return response()->json([
            'code' => 200,
            'redirect_to' => $request->get('redirect_to')
        ], 200);
    }

    public function duplicate($id)
    {
        $this->authorize("panel_webinars_duplicate");

        $user = auth()->user();
        if (!$user->isTeacher() and !$user->isOrganization()) {
            abort(404);
        }

        $webinar = Webinar::where('id', $id)
            ->where(function ($query) use ($user) {
                $query->where(function ($query) use ($user) {
                    $query->where('creator_id', $user->id)
                        ->orWhere('teacher_id', $user->id);
                });

                $query->orWhereHas('webinarPartnerTeacher', function ($query) use ($user) {
                    $query->where('teacher_id', $user->id);
                });
            })
            ->first();

        if (!empty($webinar)) {
            $new = $webinar->toArray();

            $title = $webinar->title . ' ' . trans('public.copy');
            $description = $webinar->description;
            $seo_description = $webinar->seo_description;


            $new['created_at'] = time();
            $new['updated_at'] = time();
            $new['status'] = Webinar::$pending;

            $new['slug'] = Webinar::makeSlug($title);

            foreach ($webinar->translatedAttributes as $attribute) {
                unset($new[$attribute]);
            }

            unset($new['translations']);

            $newWebinar = Webinar::create($new);

            WebinarTranslation::updateOrCreate([
                'webinar_id' => $newWebinar->id,
                'locale' => mb_strtolower($webinar->locale),
            ], [
                'title' => $title,
                'description' => $description,
                'seo_description' => $seo_description,
            ]);


            return redirect('/panel/webinars/' . $newWebinar->id . '/edit');
        }

        abort(404);
    }

    public function exportStudentsList($id)
    {
        $this->authorize("panel_webinars_export_students_list");

        $user = auth()->user();

        if (!$user->isTeacher() and !$user->isOrganization()) {
            abort(404);
        }

        $webinar = Webinar::where('id', $id)
            ->where(function ($query) use ($user) {
                $query->where(function ($query) use ($user) {
                    $query->where('creator_id', $user->id)
                        ->orWhere('teacher_id', $user->id);
                });

                $query->orWhereHas('webinarPartnerTeacher', function ($query) use ($user) {
                    $query->where('teacher_id', $user->id);
                });
            })
            ->first();

        if (!empty($webinar)) {
            $giftsIds = Gift::query()->where('webinar_id', $webinar->id)
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('date');
                    $query->orWhere('date', '<', time());
                })
                ->whereHas('sale')
                ->pluck('id')
                ->toArray();

            $sales = Sale::query()
                ->where(function ($query) use ($webinar, $giftsIds) {
                    $query->where('webinar_id', $webinar->id);
                    $query->orWhereIn('gift_id', $giftsIds);
                })
                ->whereNull('refund_at')
                ->whereHas('buyer')
                ->with([
                    'buyer' => function ($query) {
                        $query->select('id', 'full_name', 'email', 'mobile');
                    }
                ])->get();

            if (!empty($sales) and !$sales->isEmpty()) {

                foreach ($sales as $sale) {
                    if (!empty($sale->gift_id)) {
                        $gift = $sale->gift;

                        $receipt = $gift->receipt;

                        if (!empty($receipt)) {
                            $sale->buyer = $receipt;
                        } else { /* Gift recipient who has not registered yet */
                            $newUser = new User();
                            $newUser->full_name = $gift->name;
                            $newUser->email = $gift->email;

                            $sale->buyer = $newUser;
                        }
                    }
                }

                $export = new WebinarStudents($sales);
                return Excel::download($export, trans('panel.users') . '.xlsx');
            }

            $toastData = [
                'title' => trans('public.request_failed'),
                'msg' => trans('webinars.export_list_error_not_student'),
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        abort(404);
    }

    public function search(Request $request)
    {
        $user = auth()->user();

        if (!$user->isTeacher() and !$user->isOrganization()) {
            return response('', 422);
        }

        $term = $request->get('term', null);
        $webinarId = $request->get('webinar_id', null);
        $option = $request->get('option', null);

        if (!empty($term)) {
            $query = Webinar::query()->select('id', 'teacher_id')
                ->whereTranslationLike('title', '%' . $term . '%')
                ->where('id', '<>', $webinarId)
                ->with(['teacher' => function ($query) {
                    $query->select('id', 'full_name');
                }]);
            //->where('creator_id', $user->id)
            //->get();

            $webinars = $query->get();

            foreach ($webinars as $webinar) {
                $webinar->title .= ' - ' . $webinar->teacher->full_name;
            }
            return response()->json($webinars, 200);
        }

        return response('', 422);
    }

    public function getTags(Request $request, $id)
    {
        $webinarId = $request->get('webinar_id', null);

        if (!empty($webinarId)) {
            $tags = Tag::select('id', 'title')
                ->where('webinar_id', $webinarId)
                ->get();

            return response()->json($tags, 200);
        }

        return response('', 422);
    }

    public function invoice($webinarId, $saleId)
    {
        $this->authorize("panel_webinars_invoice");

        $user = auth()->user();

        $giftIds = Gift::query()
            ->where(function ($query) use ($user) {
                $query->where('email', $user->email);
                $query->orWhere('user_id', $user->id);
            })
            ->where('status', 'active')
            ->where('webinar_id', $webinarId)
            ->where(function ($query) {
                $query->whereNull('date');
                $query->orWhere('date', '<', time());
            })
            ->whereHas('sale')
            ->pluck('id')->toArray();

        $sale = Sale::query()
            ->where('id', $saleId)
            ->where(function ($query) use ($webinarId, $user, $giftIds) {
                $query->where(function ($query) use ($webinarId, $user) {
                    $query->where('buyer_id', $user->id);
                    $query->where('webinar_id', $webinarId);
                });

                if (!empty($giftIds)) {
                    $query->orWhereIn('gift_id', $giftIds);
                }
            })
            ->whereNull('refund_at')
            ->with([
                'order.orderItems',
                'buyer' => function ($query) {
                    $query->select('id', 'full_name');
                },
            ])
            ->first();

        if (!empty($sale)) {

            if (!empty($sale->gift_id)) {
                $gift = $sale->gift;

                $sale->gift_recipient = !empty($gift->receipt) ? $gift->receipt->full_name : $gift->name;
            }

            $webinar = Webinar::where('status', 'active')
                ->where('id', $webinarId)
                ->with([
                    'teacher' => function ($query) {
                        $query->select('id', 'full_name');
                    },
                    'creator' => function ($query) {
                        $query->select('id', 'full_name');
                    },
                    'webinarPartnerTeacher' => function ($query) {
                        $query->with([
                            'teacher' => function ($query) {
                                $query->select('id', 'full_name');
                            },
                        ]);
                    }
                ])
                ->first();

            if (!empty($webinar)) {
                // Get the orderItem for this sale to get correct price values
                $orderItem = null;
                if (!empty($sale->order_id)) {
                    $orderItem = \App\Models\OrderItem::where('order_id', $sale->order_id)
                        ->where('webinar_id', $webinar->id)
                        ->first();
                }

                $data = [
                    'pageTitle' => trans('webinars.invoice_page_title'),
                    'sale' => $sale,
                    'webinar' => $webinar,
                    'orderItem' => $orderItem
                ];

                return view(getTemplate() . '.panel.webinar.invoice', $data);
            }
        }

        abort(404);
    }

    public function purchases(Request $request)
    {
        $this->authorize("panel_webinars_my_purchases");

        $user = auth()->user();

        $giftsIds = Gift::query()->where('email', $user->email)
            ->where('status', 'active')
            ->whereNull('product_id')
            ->where(function ($query) {
                $query->whereNull('date');
                $query->orWhere('date', '<', time());
            })
            ->whereHas('sale')
            ->pluck('id')
            ->toArray();

        $search = $request->get('search', '');
        
        $query = Sale::query()
            ->where(function ($query) use ($user, $giftsIds) {
                $query->where('sales.buyer_id', $user->id);
                $query->orWhereIn('sales.gift_id', $giftsIds);
            })
            ->whereNull('sales.refund_at')
            // Include sales with access_to_purchased_item = false if they have active SubscribeUse
            // This handles cases where subject was canceled but then resubscribed
            ->where(function ($query) {
                $query->where('access_to_purchased_item', true)
                    ->orWhere(function ($q) {
                        // Will be filtered later based on active SubscribeUse
                        $q->where('access_to_purchased_item', false)
                            ->where('payment_method', Sale::$subscribe);
                    });
            })
            ->where(function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->whereNotNull('sales.webinar_id')
                        ->where('sales.type', 'webinar')
                        ->whereHas('webinar', function ($query) use ($search) {
                            $query->where('status', 'active');
                            if (!empty($search)) {
                                $query->whereTranslationLike('title', "%{$search}%");
                            }
                        });
                });
                $query->orWhere(function ($query) use ($search) {
                    $query->whereNotNull('sales.bundle_id')
                        ->where('sales.type', 'bundle')
                        ->whereHas('bundle', function ($query) use ($search) {
                            $query->where('status', 'active');
                            if (!empty($search)) {
                                $query->whereTranslationLike('title', "%{$search}%");
                            }
                        });
                });
                $query->orWhere(function ($query) {
                    $query->whereNotNull('gift_id');
                    $query->whereHas('gift');
                });
            });

        $allSales = deepClone($query)
            ->with([
                'webinar' => function ($query) {
                    $query->with([
                        'files',
                        'reviews' => function ($query) {
                            $query->where('status', 'active');
                        },
                        'category',
                        'teacher' => function ($query) {
                            $query->select('id', 'full_name');
                        },
                    ]);
                    $query->withCount([
                        'sales' => function ($query) {
                            $query->whereNull('refund_at');
                        }
                    ]);
                },
                'bundle' => function ($query) {
                    $query->with([
                        'reviews' => function ($query) {
                            $query->where('status', 'active');
                        },
                        'category',
                        'teacher' => function ($query) {
                            $query->select('id', 'full_name');
                        },
                    ]);
                },
                'gift' => function ($query) {
                    $query->with(['webinar', 'bundle']);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            // Filter out subscription purchases where SubscribeUse is expired
            // Also include sales with access_to_purchased_item = false if they have active SubscribeUse
            ->filter(function ($sale) use ($user) {
                // If it's not a subscription purchase, only keep if access is enabled
                if ($sale->payment_method != Sale::$subscribe) {
                    return $sale->access_to_purchased_item;
                }
                
                // For subscription purchases, check if SubscribeUse is active AND subscription plan is not expired
                // This allows showing subjects even if access_to_purchased_item = false (e.g., after resubscribe)
                if (!empty($sale->webinar_id)) {
                    $subscribeUse = \App\Models\SubscribeUse::where('user_id', $user->id)
                        ->where('webinar_id', $sale->webinar_id)
                        ->where('active', true)
                        ->where(function ($q) {
                            $q->whereNull('expired_at')
                                ->orWhere('expired_at', '>', time());
                        })
                        ->first();
                    
                    if ($subscribeUse) {
                        // Check if the SubscribeUse's sale_id points to an active subscription sale
                        $subscribeSale = null;
                        if (!empty($subscribeUse->sale_id)) {
                            $subscribeSale = Sale::where('id', $subscribeUse->sale_id)
                                ->where('buyer_id', $user->id)
                                ->where('type', Sale::$subscribe)
                                ->whereNull('refund_at')
                                ->first();
                        }
                        
                        // If not found via sale_id, find by subscribe_id
                        if (!$subscribeSale) {
                            $subscribeSale = Sale::where('buyer_id', $user->id)
                                ->where('type', Sale::$subscribe)
                                ->where('subscribe_id', $subscribeUse->subscribe_id)
                                ->whereNull('refund_at')
                                ->latest('created_at')
                                ->first();
                        }
                        
                        if ($subscribeSale && $subscribeSale->subscribe) {
                            // Use the same expiration logic as getActiveSubscribes()
                            // Honor custom_expiration_date if set (could be from renewal extension)
                            // But cap it at a reasonable maximum (purchase_date + subscription_days * 3) to prevent bugs
                            $daysSincePurchase = (int)diffTimestampDay(time(), $subscribeSale->created_at);
                            $calculatedExpiration = $subscribeSale->created_at + ($subscribeSale->subscribe->days * 86400);
                            $maxReasonableExpiration = $subscribeSale->created_at + (($subscribeSale->subscribe->days * 3) + 7) * 86400;
                            
                            $isExpired = false;
                            if (!empty($subscribeSale->custom_expiration_date)) {
                                // Check if custom_expiration_date is within reasonable bounds
                                if ($subscribeSale->custom_expiration_date > $maxReasonableExpiration) {
                                    // Custom expiration is unreasonably far in the future - likely a bug
                                    // Use calculated expiration instead
                                    $effectiveExpiration = $calculatedExpiration;
                                } else {
                                    // Custom expiration is within reasonable bounds - trust it (could be from renewal)
                                    $effectiveExpiration = $subscribeSale->custom_expiration_date;
                                }
                                $isExpired = $effectiveExpiration <= time();
                            } else {
                                // Use original logic: check if days > countDayOfSale
                                $isExpired = $subscribeSale->subscribe->days > 0 && 
                                            $subscribeSale->subscribe->days <= $daysSincePurchase;
                            }
                            
                            if (!$isExpired) {
                                // Update access_to_purchased_item if it was false (for display purposes)
                                if (!$sale->access_to_purchased_item) {
                                    $sale->access_to_purchased_item = true;
                                }
                                return true;
                            }
                        }
                    }
                    return false;
                } elseif (!empty($sale->bundle_id)) {
                    $subscribeUse = \App\Models\SubscribeUse::where('user_id', $user->id)
                        ->where('bundle_id', $sale->bundle_id)
                        ->where('active', true)
                        ->where(function ($q) {
                            $q->whereNull('expired_at')
                                ->orWhere('expired_at', '>', time());
                        })
                        ->first();
                    
                    if ($subscribeUse) {
                        // Check if the SubscribeUse's sale_id points to an active subscription sale
                        $subscribeSale = null;
                        if (!empty($subscribeUse->sale_id)) {
                            $subscribeSale = Sale::where('id', $subscribeUse->sale_id)
                                ->where('buyer_id', $user->id)
                                ->where('type', Sale::$subscribe)
                                ->whereNull('refund_at')
                                ->first();
                        }
                        
                        // If not found via sale_id, find by subscribe_id
                        if (!$subscribeSale) {
                            $subscribeSale = Sale::where('buyer_id', $user->id)
                                ->where('type', Sale::$subscribe)
                                ->where('subscribe_id', $subscribeUse->subscribe_id)
                                ->whereNull('refund_at')
                                ->latest('created_at')
                                ->first();
                        }
                        
                        if ($subscribeSale && $subscribeSale->subscribe) {
                            // Use the same expiration logic as getActiveSubscribes()
                            // Honor custom_expiration_date if set (could be from renewal extension)
                            // But cap it at a reasonable maximum (purchase_date + subscription_days * 3) to prevent bugs
                            $daysSincePurchase = (int)diffTimestampDay(time(), $subscribeSale->created_at);
                            $calculatedExpiration = $subscribeSale->created_at + ($subscribeSale->subscribe->days * 86400);
                            $maxReasonableExpiration = $subscribeSale->created_at + (($subscribeSale->subscribe->days * 3) + 7) * 86400;
                            
                            $isExpired = false;
                            if (!empty($subscribeSale->custom_expiration_date)) {
                                // Check if custom_expiration_date is within reasonable bounds
                                if ($subscribeSale->custom_expiration_date > $maxReasonableExpiration) {
                                    // Custom expiration is unreasonably far in the future - likely a bug
                                    // Use calculated expiration instead
                                    $effectiveExpiration = $calculatedExpiration;
                                } else {
                                    // Custom expiration is within reasonable bounds - trust it (could be from renewal)
                                    $effectiveExpiration = $subscribeSale->custom_expiration_date;
                                }
                                $isExpired = $effectiveExpiration <= time();
                            } else {
                                // Use original logic: check if days > countDayOfSale
                                $isExpired = $subscribeSale->subscribe->days > 0 && 
                                            $subscribeSale->subscribe->days <= $daysSincePurchase;
                            }
                            
                            if (!$isExpired) {
                                // Update access_to_purchased_item if it was false (for display purposes)
                                if (!$sale->access_to_purchased_item) {
                                    $sale->access_to_purchased_item = true;
                                }
                                return true;
                            }
                        }
                    }
                    return false;
                }
                
                return false;
            })
            ->values();
        
        // Also include subjects that have active SubscribeUse records but no Sale record
        $activeSubscribeUses = \App\Models\SubscribeUse::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNotNull('webinar_id')
                    ->orWhereNotNull('bundle_id');
            })
            ->where('active', true)
            ->where(function ($q) {
                $q->whereNull('expired_at')
                    ->orWhere('expired_at', '>', time());
            })
            ->get();
        
        $virtualSales = collect();
        foreach ($activeSubscribeUses as $use) {
            $itemId = !empty($use->webinar_id) ? $use->webinar_id : $use->bundle_id;
            $itemName = !empty($use->webinar_id) ? 'webinar_id' : 'bundle_id';
            
            // Check if subscription plan is expired
            $subscribeSale = Sale::where('buyer_id', $user->id)
                ->where('type', Sale::$subscribe)
                ->where('subscribe_id', $use->subscribe_id)
                ->whereNull('refund_at')
                ->latest('created_at')
                ->first();
            
            if ($subscribeSale && $subscribeSale->subscribe) {
                // Use the same expiration logic as getActiveSubscribes()
                // Honor custom_expiration_date if set (could be from renewal extension)
                // But cap it at a reasonable maximum (purchase_date + subscription_days * 3) to prevent bugs
                $daysSincePurchase = (int)diffTimestampDay(time(), $subscribeSale->created_at);
                $calculatedExpiration = $subscribeSale->created_at + ($subscribeSale->subscribe->days * 86400);
                $maxReasonableExpiration = $subscribeSale->created_at + (($subscribeSale->subscribe->days * 3) + 7) * 86400;
                
                $isExpired = false;
                if (!empty($subscribeSale->custom_expiration_date)) {
                    // Check if custom_expiration_date is within reasonable bounds
                    if ($subscribeSale->custom_expiration_date > $maxReasonableExpiration) {
                        // Custom expiration is unreasonably far in the future - likely a bug
                        // Use calculated expiration instead
                        $effectiveExpiration = $calculatedExpiration;
                    } else {
                        // Custom expiration is within reasonable bounds - trust it (could be from renewal)
                        $effectiveExpiration = $subscribeSale->custom_expiration_date;
                    }
                    $isExpired = $effectiveExpiration <= time();
                } else {
                    // Use original logic: check if days > countDayOfSale
                    $isExpired = $subscribeSale->subscribe->days > 0 && 
                               $subscribeSale->subscribe->days <= $daysSincePurchase;
                }
                
                // Only include if subscription is not expired
                if (!$isExpired) {
                    // Check if we already have a Sale record for this item (in the query results)
                    $existingSale = $allSales->first(function($sale) use ($itemId, $itemName) {
                        if ($itemName == 'webinar_id') {
                            return !empty($sale->webinar_id) && $sale->webinar_id == $itemId;
                        } else {
                            return !empty($sale->bundle_id) && $sale->bundle_id == $itemId;
                        }
                    });
                    
                    // Check if there's a Sale record in the database (even if refunded) - this indicates the subject was purchased
                    $hasPurchaseHistory = Sale::where('buyer_id', $user->id)
                        ->where($itemName, $itemId)
                        ->where('type', $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle)
                        ->exists();
                    
                    // Check if SubscribeUse is linked to an active subscription sale
                    // The SubscribeUse's sale_id should point to an active, non-refunded subscription sale
                    // OR we can verify by checking if the subscribe_id matches an active subscription
                    $isLinkedToActiveSubscription = false;
                    
                    // First, check if the SubscribeUse's sale_id points to an active subscription sale
                    if (!empty($use->sale_id)) {
                        $subscribeSaleFromUse = Sale::where('id', $use->sale_id)
                            ->where('buyer_id', $user->id)
                            ->where('type', Sale::$subscribe)
                            ->whereNull('refund_at')
                            ->first();
                        
                        if ($subscribeSaleFromUse && $subscribeSaleFromUse->subscribe) {
                            // Verify the subscription is not expired using the same logic as getActiveSubscribes()
                            $daysSincePurchase = (int)diffTimestampDay(time(), $subscribeSaleFromUse->created_at);
                            $calculatedExpiration = $subscribeSaleFromUse->created_at + ($subscribeSaleFromUse->subscribe->days * 86400);
                            $maxReasonableExpiration = $subscribeSaleFromUse->created_at + (($subscribeSaleFromUse->subscribe->days * 3) + 7) * 86400;
                            
                            $isExpired = false;
                            if (!empty($subscribeSaleFromUse->custom_expiration_date)) {
                                if ($subscribeSaleFromUse->custom_expiration_date > $maxReasonableExpiration) {
                                    $effectiveExpiration = $calculatedExpiration;
                                } else {
                                    $effectiveExpiration = $subscribeSaleFromUse->custom_expiration_date;
                                }
                                $isExpired = $effectiveExpiration <= time();
                            } else {
                                $isExpired = $subscribeSaleFromUse->subscribe->days > 0 && 
                                           $subscribeSaleFromUse->subscribe->days <= $daysSincePurchase;
                            }
                            
                            if (!$isExpired) {
                                $isLinkedToActiveSubscription = true;
                            }
                        }
                    }
                    
                    // If not linked via sale_id, check if subscribe_id matches any active subscription
                    if (!$isLinkedToActiveSubscription) {
                        $activeSubscribeSale = Sale::where('buyer_id', $user->id)
                            ->where('type', Sale::$subscribe)
                            ->where('subscribe_id', $use->subscribe_id)
                            ->whereNull('refund_at')
                            ->latest('created_at')
                            ->first();
                        
                        if ($activeSubscribeSale && $activeSubscribeSale->subscribe) {
                            // Use the same expiration logic as getActiveSubscribes()
                            $daysSincePurchase = (int)diffTimestampDay(time(), $activeSubscribeSale->created_at);
                            $calculatedExpiration = $activeSubscribeSale->created_at + ($activeSubscribeSale->subscribe->days * 86400);
                            $maxReasonableExpiration = $activeSubscribeSale->created_at + (($activeSubscribeSale->subscribe->days * 3) + 7) * 86400;
                            
                            $isExpired = false;
                            if (!empty($activeSubscribeSale->custom_expiration_date)) {
                                if ($activeSubscribeSale->custom_expiration_date > $maxReasonableExpiration) {
                                    $effectiveExpiration = $calculatedExpiration;
                                } else {
                                    $effectiveExpiration = $activeSubscribeSale->custom_expiration_date;
                                }
                                $isExpired = $effectiveExpiration <= time();
                            } else {
                                $isExpired = $activeSubscribeSale->subscribe->days > 0 && 
                                           $activeSubscribeSale->subscribe->days <= $daysSincePurchase;
                            }
                            
                            if (!$isExpired) {
                                $isLinkedToActiveSubscription = true;
                            }
                        }
                    }
                    
                    // Only create virtual sale if:
                    // 1. No existing sale in query results, AND
                    // 2. Subject was purchased at some point (has Sale record), AND
                    // 3. SubscribeUse is linked to an active subscription, AND
                    // 4. No virtual sale already created for this item
                    if (!$existingSale && $hasPurchaseHistory && $isLinkedToActiveSubscription) {
                        // Check if we already added a virtual sale for this item (avoid duplicates)
                        $existingVirtualSale = $virtualSales->first(function($sale) use ($itemId, $itemName) {
                            if ($itemName == 'webinar_id') {
                                return !empty($sale->webinar_id) && $sale->webinar_id == $itemId;
                            } else {
                                return !empty($sale->bundle_id) && $sale->bundle_id == $itemId;
                            }
                        });
                        
                        if (!$existingVirtualSale) {
                            $itemQuery = $itemName == 'webinar_id' 
                                ? Webinar::where('id', $itemId)->where('status', 'active')
                                : \App\Models\Bundle::where('id', $itemId)->where('status', 'active');
                            
                            // Apply category filter if specified
                            if (!empty($categoryId)) {
                                $itemQuery->where('category_id', $categoryId);
                            }
                            
                            $item = $itemQuery->first();
                            
                            if ($item) {
                                $virtualSale = new Sale();
                                // Use unique negative ID to avoid conflicts (start from -1 and go down)
                                $virtualSale->id = -1 * ($virtualSales->count() + 1);
                                $virtualSale->buyer_id = $user->id;
                                $virtualSale->payment_method = Sale::$subscribe;
                                $virtualSale->type = $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle;
                                if ($itemName == 'webinar_id') {
                                    $virtualSale->webinar_id = $itemId;
                                    $virtualSale->setRelation('webinar', $item);
                                } else {
                                    $virtualSale->bundle_id = $itemId;
                                    $virtualSale->setRelation('bundle', $item);
                                }
                                $virtualSale->created_at = $use->sale ? $use->sale->created_at : time();
                                $virtualSale->access_to_purchased_item = true;
                                $virtualSale->refund_at = null; // Ensure it's not marked as refunded
                                
                                // Load buyer relationship for display
                                $virtualSale->setRelation('buyer', $user);
                                
                                $virtualSales->push($virtualSale);
                                
                                \Log::info('Created virtual sale for purchases page', [
                                    'user_id' => $user->id,
                                    'item_id' => $itemId,
                                    'item_name' => $itemName,
                                    'virtual_sale_id' => $virtualSale->id,
                                    'item_title' => $item->title ?? 'N/A'
                                ]);
                            }
                        }
                    }
                }
            }
        }
        
        // Merge virtual sales with regular sales and sort by created_at
        $allSales = $allSales->merge($virtualSales)->sortByDesc('created_at')->values();

        $time = time();

        $giftDurations = 0;
        $giftUpcoming = 0;
        $giftPurchasedCount = 0;

        // Process gifts for all sales first (before separating)
        foreach ($allSales as $sale) {
            if (!empty($sale->gift_id)) {
                $gift = $sale->gift;

                $sale->webinar_id = $gift->webinar_id;
                $sale->bundle_id = $gift->bundle_id;

                $sale->webinar = !empty($gift->webinar_id) ? $gift->webinar : null;
                $sale->bundle = !empty($gift->bundle_id) ? $gift->bundle : null;

                $sale->gift_recipient = !empty($gift->receipt) ? $gift->receipt->full_name : $gift->name;
                $sale->gift_sender = $sale->buyer->full_name;
                $sale->gift_date = $gift->date;;

                $giftPurchasedCount += 1;

                if (!empty($sale->webinar)) {
                    $giftDurations += $sale->webinar->duration;

                    if ($sale->webinar->start_date > $time) {
                        $giftUpcoming += 1;
                    }
                }

                if (!empty($sale->bundle)) {
                    $bundleWebinars = $sale->bundle->bundleWebinars;

                    foreach ($bundleWebinars as $bundleWebinar) {
                        $giftDurations += $bundleWebinar->webinar->duration;
                    }
                }
            }
        }

        // Filter out expired subscription-based purchases
        // Then separate live_now items from regular items (after processing gifts)
        $validSales = collect();
        foreach ($allSales as $sale) {
            $item = !empty($sale->webinar) ? $sale->webinar : $sale->bundle;
            
            // Apply category filter if specified (after gifts and virtual sales are processed)
            if (!empty($categoryId)) {
                $itemCategoryId = null;
                
                // Get the item (webinar or bundle)
                if (empty($item)) {
                    // Try to load the item if not already loaded
                    if (!empty($sale->webinar_id)) {
                        $item = $sale->webinar ?? Webinar::where('id', $sale->webinar_id)->first();
                    } elseif (!empty($sale->bundle_id)) {
                        $item = $sale->bundle ?? \App\Models\Bundle::where('id', $sale->bundle_id)->first();
                    }
                }
                
                // Check item category
                if (!empty($item) && !empty($item->category_id)) {
                    $itemCategoryId = $item->category_id;
                }
                
                // For gifts, check the gift's item category
                if (empty($itemCategoryId) && !empty($sale->gift_id)) {
                    if ($sale->gift) {
                        $giftItem = null;
                        if (!empty($sale->gift->webinar_id)) {
                            $giftItem = $sale->gift->webinar ?? Webinar::where('id', $sale->gift->webinar_id)->first();
                        } elseif (!empty($sale->gift->bundle_id)) {
                            $giftItem = $sale->gift->bundle ?? \App\Models\Bundle::where('id', $sale->gift->bundle_id)->first();
                        }
                        if ($giftItem && !empty($giftItem->category_id)) {
                            $itemCategoryId = $giftItem->category_id;
                        }
                    }
                }
                
                // Skip if category doesn't match
                if (empty($itemCategoryId) || $itemCategoryId != $categoryId) {
                    continue;
                }
            }
            
            // Skip if this is a subscription purchase and the subscription has expired
            if (!empty($item) && $sale->payment_method == Sale::$subscribe) {
                $itemId = !empty($sale->webinar_id) ? $sale->webinar_id : $sale->bundle_id;
                $itemName = !empty($sale->webinar_id) ? 'webinar_id' : 'bundle_id';
                
                // For virtual sales (id < 0), check expiration directly using SubscribeUse
                // For regular sales, use the checkExpiredPurchaseWithSubscribe method
                $isExpired = false;
                if ($sale->id < 0) {
                    // Virtual sale - check expiration directly
                    $activeUses = \App\Models\SubscribeUse::where('user_id', $sale->buyer_id)
                        ->where($itemName, $itemId)
                        ->where('active', true)
                        ->where(function($q) {
                            $q->whereNull('expired_at')->orWhere('expired_at', '>', time());
                        })
                        ->get();
                    
                    $hasNonExpiredSubscription = false;
                    foreach ($activeUses as $use) {
                        $subscribeSale = Sale::where('buyer_id', $sale->buyer_id)
                            ->where('type', Sale::$subscribe)
                            ->where('subscribe_id', $use->subscribe_id)
                            ->whereNull('refund_at')
                            ->latest('created_at')
                            ->first();
                        
                        if ($subscribeSale && $subscribeSale->subscribe) {
                            // Use the same expiration logic as getActiveSubscribes()
                            // Honor custom_expiration_date if set (could be from renewal extension)
                            $daysSincePurchase = (int)diffTimestampDay(time(), $subscribeSale->created_at);
                            $calculatedExpiration = $subscribeSale->created_at + ($subscribeSale->subscribe->days * 86400);
                            $maxReasonableExpiration = $subscribeSale->created_at + (($subscribeSale->subscribe->days * 3) + 7) * 86400;
                            
                            $isSubscriptionExpired = false;
                            if (!empty($subscribeSale->custom_expiration_date)) {
                                if ($subscribeSale->custom_expiration_date > $maxReasonableExpiration) {
                                    $effectiveExpiration = $calculatedExpiration;
                                } else {
                                    $effectiveExpiration = $subscribeSale->custom_expiration_date;
                                }
                                $isSubscriptionExpired = $effectiveExpiration <= time();
                            } else {
                                $isSubscriptionExpired = $subscribeSale->subscribe->days > 0 && 
                                                       $subscribeSale->subscribe->days <= $daysSincePurchase;
                            }
                            
                            if (!$isSubscriptionExpired) {
                                $hasNonExpiredSubscription = true;
                                break;
                            }
                        }
                    }
                    $isExpired = !$hasNonExpiredSubscription;
                } else {
                    // Regular sale - use the existing method
                    $isExpired = $sale->checkExpiredPurchaseWithSubscribe($sale->buyer_id, $itemId, $itemName);
                }
                
                if ($isExpired) {
                    // Subscription expired, skip this sale
                    continue;
                }
            }
            
            $validSales->push($sale);
        }

        // Deduplicate sales by webinar_id or bundle_id (keep the most recent one)
        $deduplicatedSales = collect();
        $seenItems = [];
        
        foreach ($validSales->sortByDesc('created_at') as $sale) {
            $itemId = !empty($sale->webinar_id) ? $sale->webinar_id : $sale->bundle_id;
            $itemKey = !empty($sale->webinar_id) ? 'webinar_' . $itemId : 'bundle_' . $itemId;
            
            // Only add if we haven't seen this item before
            if (!isset($seenItems[$itemKey])) {
                $seenItems[$itemKey] = true;
                $deduplicatedSales->push($sale);
            }
        }

        // Separate live_now items from regular items
        $liveNowSales = collect();
        $regularSales = collect();

        foreach ($deduplicatedSales as $sale) {
            $item = !empty($sale->webinar) ? $sale->webinar : $sale->bundle;
            if (!empty($item) && !empty($sale->webinar) && $sale->webinar->type == 'webinar' && !empty($sale->webinar->live_now)) {
                $liveNowSales->push($sale);
            } else {
                $regularSales->push($sale);
            }
        }

        // Paginate regular sales
        $currentPage = $request->get('page', 1);
        $perPage = 10;
        $regularSalesPaginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $regularSales->forPage($currentPage, $perPage),
            $regularSales->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $sales = $regularSalesPaginated;

        $purchasedCount = $deduplicatedSales->where(function($sale) {
            return !empty($sale->webinar) || !empty($sale->bundle);
        })->count();

        // Calculate hours from deduplicated sales only
        $webinarsHours = 0;
        $bundlesHours = 0;
        foreach ($deduplicatedSales as $sale) {
            if (!empty($sale->webinar)) {
                $webinarsHours += $sale->webinar->duration ?? 0;
            } elseif (!empty($sale->bundle)) {
                $bundleWebinars = $sale->bundle->bundleWebinars ?? collect();
                foreach ($bundleWebinars as $bundleWebinar) {
                    if (!empty($bundleWebinar->webinar)) {
                        $bundlesHours += $bundleWebinar->webinar->duration ?? 0;
                    }
                }
            }
        }

        $hours = $webinarsHours + $bundlesHours + $giftDurations;

        $upComing = $deduplicatedSales->filter(function($sale) use ($time) {
            return !empty($sale->webinar) && !empty($sale->webinar->start_date) && $sale->webinar->start_date > $time;
        })->count();

        // Calculate expired subscription-based purchases count
        // Get all SubscribeUse records to find expired subjects (even if subject purchase sales don't exist)
        $expiredPurchasesCount = 0;
        $expiredSales = collect();
        
        // Get all SubscribeUse records for webinars and bundles
        $allSubscribeUses = \App\Models\SubscribeUse::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNotNull('webinar_id')
                    ->orWhereNotNull('bundle_id');
            })
            ->with(['sale'])
            ->get();
        
        // Removed excessive logging - was causing performance issues with repeated calculations
        
        // Group SubscribeUse records by item to check if ALL active uses are expired
        $usesByItem = [];
        foreach ($allSubscribeUses as $use) {
            $itemId = !empty($use->webinar_id) ? $use->webinar_id : $use->bundle_id;
            $itemName = !empty($use->webinar_id) ? 'webinar_id' : 'bundle_id';
            
            if (empty($itemId)) {
                continue;
            }
            
            $key = $itemName . '_' . $itemId;
            if (!isset($usesByItem[$key])) {
                $usesByItem[$key] = [
                    'item_id' => $itemId,
                    'item_name' => $itemName,
                    'uses' => []
                ];
            }
            $usesByItem[$key]['uses'][] = $use;
        }
        
        // Check each item to see if ALL active uses have expired subscriptions
        foreach ($usesByItem as $itemData) {
            $itemId = $itemData['item_id'];
            $itemName = $itemData['item_name'];
            $uses = $itemData['uses'];
            
            // Find all active SubscribeUse records for this item
            $activeUses = [];
            foreach ($uses as $use) {
                $isUseActive = $use->active && (is_null($use->expired_at) || $use->expired_at > time());
                if ($isUseActive) {
                    $activeUses[] = $use;
                }
            }
            
            // If no active uses, subject is expired
            if (empty($activeUses)) {
                // Subject has no active uses - mark as expired
                $this->addExpiredSaleToCollection($user, $itemId, $itemName, $expiredSales, $expiredPurchasesCount);
                continue;
            }
            
            // Check if ALL active uses have expired subscriptions
            $allExpired = true;
            foreach ($activeUses as $use) {
                $subscribeSale = Sale::where('buyer_id', $user->id)
                        ->where('type', Sale::$subscribe)
                    ->where('subscribe_id', $use->subscribe_id)
                    ->whereNull('refund_at')
                    ->latest('created_at')
                    ->first();
                
                if ($subscribeSale && $subscribeSale->subscribe) {
                    $daysSincePurchase = (int)diffTimestampDay(time(), $subscribeSale->created_at);
                    $isSubscriptionExpired = $subscribeSale->subscribe->days > 0 && 
                                           $subscribeSale->subscribe->days <= $daysSincePurchase;
                    
                    // If at least one active use has a non-expired subscription, subject is not expired
                    if (!$isSubscriptionExpired) {
                        $allExpired = false;
                        break;
                    }
                } else {
                    // No subscription sale found - assume not expired
                    $allExpired = false;
                    break;
                }
            }
            
            // Only mark as expired if ALL active uses have expired subscriptions
            if ($allExpired) {
                $this->addExpiredSaleToCollection($user, $itemId, $itemName, $expiredSales, $expiredPurchasesCount);
            }
        }
        
        // Removed excessive logging - calculation result is used directly

        // Count live now sales (including gifts)
        $liveNowCount = $liveNowSales->count();
        // Also count gift sales that are live now
        foreach ($allSales as $sale) {
            if (!empty($sale->gift_id) && !empty($sale->webinar) && $sale->webinar->type == 'webinar' && !empty($sale->webinar->live_now)) {
                $liveNowCount += 1;
            }
        }

        $data = [
            'pageTitle' => trans('webinars.webinars_purchases_page_title'),
            'sales' => $sales,
            'liveNowSales' => $liveNowSales,
            'purchasedCount' => $purchasedCount + $giftPurchasedCount,
            'hours' => $hours,
            'upComing' => $liveNowCount, // Changed to live now count
            'expiredPurchasesCount' => $expiredPurchasesCount,
            'search' => $search
        ];

        return view(getTemplate() . '.panel.webinar.purchases', $data);
    }
    
    public function expiredSubjects(Request $request)
    {
        $this->authorize("panel_webinars_my_purchases");

        $user = auth()->user();
        $search = $request->get('search', '');

        // Calculate expired subscription-based purchases
        // Get all SubscribeUse records to find expired subjects (even if subject purchase sales don't exist)
        $expiredPurchasesCount = 0;
        $expiredSales = collect();
        
        // Get all SubscribeUse records for webinars and bundles
        $allSubscribeUses = \App\Models\SubscribeUse::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNotNull('webinar_id')
                    ->orWhereNotNull('bundle_id');
            })
            ->with(['sale'])
            ->get();
        
        // Group SubscribeUse records by item to check if ALL active uses are expired
        $usesByItem = [];
        foreach ($allSubscribeUses as $use) {
            $itemId = !empty($use->webinar_id) ? $use->webinar_id : $use->bundle_id;
            $itemName = !empty($use->webinar_id) ? 'webinar_id' : 'bundle_id';
            
            if (empty($itemId)) {
                continue;
            }
            
            $key = $itemName . '_' . $itemId;
            if (!isset($usesByItem[$key])) {
                $usesByItem[$key] = [
                    'item_id' => $itemId,
                    'item_name' => $itemName,
                    'uses' => []
                ];
            }
            $usesByItem[$key]['uses'][] = $use;
        }
        
        // Check each item to see if ALL active uses have expired subscriptions
        foreach ($usesByItem as $itemData) {
            $itemId = $itemData['item_id'];
            $itemName = $itemData['item_name'];
            $uses = $itemData['uses'];
            
            // Find all active SubscribeUse records for this item
            $activeUses = [];
            foreach ($uses as $use) {
                $isUseActive = $use->active && (is_null($use->expired_at) || $use->expired_at > time());
                if ($isUseActive) {
                    $activeUses[] = $use;
                }
            }
            
            // If no active uses, subject is expired
            if (empty($activeUses)) {
                // Subject has no active uses - mark as expired
                $this->addExpiredSaleToCollection($user, $itemId, $itemName, $expiredSales, $expiredPurchasesCount);
                continue;
            }
            
            // Check if ALL active uses have expired subscriptions
            // Use the same expiration logic as getActiveSubscribes() to honor custom_expiration_date
            $allExpired = true;
            foreach ($activeUses as $use) {
                // Check both sale_id and installment_order_id
                $subscribeSale = null;
                if (!empty($use->sale_id)) {
                    $subscribeSale = Sale::where('buyer_id', $user->id)
                        ->where('type', Sale::$subscribe)
                        ->where('subscribe_id', $use->subscribe_id)
                        ->where('id', $use->sale_id)
                        ->whereNull('refund_at')
                        ->first();
                } elseif (!empty($use->installment_order_id)) {
                    // For installment orders, check if the installment order is still active
                    $installmentOrder = \App\Models\InstallmentOrder::where('id', $use->installment_order_id)
                        ->where('user_id', $user->id)
                        ->where('subscribe_id', $use->subscribe_id)
                        ->where('status', 'open')
                        ->whereNull('refund_at')
                        ->first();
                    
                    if ($installmentOrder && $installmentOrder->subscribe) {
                        $createdAt = \Carbon\Carbon::createFromTimestamp($installmentOrder->created_at);
                        $now = \Carbon\Carbon::now();
                        $countDayOfSale = $createdAt->diffInDays($now);
                        $isSubscriptionExpired = $installmentOrder->subscribe->days > 0 && 
                                               $installmentOrder->subscribe->days <= $countDayOfSale;
                        
                        // If installment subscription is not expired, subject is not expired
                        if (!$isSubscriptionExpired) {
                            $allExpired = false;
                            break;
                        }
                    } else {
                        // Installment order not found or not active - assume expired
                        continue;
                    }
                }
                
                if ($subscribeSale && $subscribeSale->subscribe) {
                    $daysSincePurchase = (int)diffTimestampDay(time(), $subscribeSale->created_at);
                    
                    // Use the same expiration logic as getActiveSubscribes()
                    // Honor custom_expiration_date if set (could be from renewal extension)
                    $calculatedExpiration = $subscribeSale->created_at + ($subscribeSale->subscribe->days * 86400);
                    $maxReasonableExpiration = $subscribeSale->created_at + (($subscribeSale->subscribe->days * 3) + 7) * 86400;
                    
                    $isSubscriptionExpired = false;
                    if (!empty($subscribeSale->custom_expiration_date)) {
                        // Check if custom_expiration_date is within reasonable bounds
                        if ($subscribeSale->custom_expiration_date > $maxReasonableExpiration) {
                            $effectiveExpiration = $calculatedExpiration;
                        } else {
                            $effectiveExpiration = $subscribeSale->custom_expiration_date;
                        }
                        $isSubscriptionExpired = $effectiveExpiration <= time();
                    } else {
                        // Use original logic: check if days <= daysSincePurchase
                        $isSubscriptionExpired = $subscribeSale->subscribe->days > 0 && 
                                               $subscribeSale->subscribe->days <= $daysSincePurchase;
                    }
                    
                    // If at least one active use has a non-expired subscription, subject is not expired
                    if (!$isSubscriptionExpired) {
                        $allExpired = false;
                        break;
                    }
                } else {
                    // No subscription sale found for this use - check if use is linked to an active subscription
                    // If the use has an active subscription (via getActiveSubscribes), it's not expired
                    $activeSubscribes = \App\Models\Subscribe::getActiveSubscribes($user->id);
                    $hasActiveSubscription = $activeSubscribes->contains(function($sub) use ($use) {
                        return $sub->id == $use->subscribe_id;
                    });
                    
                    if ($hasActiveSubscription) {
                        // User has an active subscription for this subscribe_id - subject is not expired
                        $allExpired = false;
                        break;
                    }
                }
            }
            
            // Only mark as expired if ALL active uses have expired subscriptions
            if ($allExpired) {
                $this->addExpiredSaleToCollection($user, $itemId, $itemName, $expiredSales, $expiredPurchasesCount);
            }
        }
        
        // Apply search filter if provided
        if (!empty($search)) {
            $expiredSales = $expiredSales->filter(function($sale) use ($search) {
                $item = !empty($sale->webinar) ? $sale->webinar : $sale->bundle;
                if (empty($item)) {
                    return false;
                }
                return stripos($item->title, $search) !== false;
            });
        }
        
        // Load relationships for all expired sales
        foreach ($expiredSales as $sale) {
            if (!empty($sale->webinar_id) && !$sale->relationLoaded('webinar')) {
                $sale->load(['webinar' => function ($query) {
                    $query->with([
                        'files',
                        'reviews' => function ($query) {
                            $query->where('status', 'active');
                        },
                        'category',
                        'teacher' => function ($query) {
                            $query->select('id', 'full_name');
                        },
                    ]);
                }]);
            }
            if (!empty($sale->bundle_id) && !$sale->relationLoaded('bundle')) {
                $sale->load(['bundle' => function ($query) {
                    $query->with([
                        'reviews' => function ($query) {
                            $query->where('status', 'active');
                        },
                        'category',
                        'teacher' => function ($query) {
                            $query->select('id', 'full_name');
                        },
                    ]);
                }]);
            }
        }
        
        // Paginate expired sales
        $currentPage = $request->get('page', 1);
        $perPage = 10;
        $expiredSalesPaginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $expiredSales->forPage($currentPage, $perPage),
            $expiredSales->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $data = [
            'pageTitle' => trans('panel.my_expired_subject'),
            'expiredSales' => $expiredSalesPaginated,
            'expiredPurchasesCount' => $expiredPurchasesCount,
            'search' => $search
        ];

        return view(getTemplate() . '.panel.webinar.expired-subjects', $data);
    }
    
    /**
     * Helper method to add expired sale to collection
     */
    private function addExpiredSaleToCollection($user, $itemId, $itemName, &$expiredSales, &$expiredPurchasesCount)
    {
        // Create a sale object for display (or find existing one)
        $sale = Sale::where('buyer_id', $user->id)
            ->where('payment_method', Sale::$subscribe)
            ->where($itemName, $itemId)
            ->whereNull('refund_at')
            ->first();
        
        // If no sale exists, create a virtual sale object for display
        if (!$sale) {
            $sale = new Sale();
            $sale->id = 0; // Virtual sale
            $sale->buyer_id = $user->id;
            $sale->payment_method = Sale::$subscribe;
            $sale->type = $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle;
            if ($itemName == 'webinar_id') {
                $sale->webinar_id = $itemId;
                // Load webinar relationship
                $webinar = Webinar::find($itemId);
                if ($webinar) {
                    $sale->setRelation('webinar', $webinar);
                }
            } else {
                $sale->bundle_id = $itemId;
                // Load bundle relationship
                $bundle = \App\Models\Bundle::find($itemId);
                if ($bundle) {
                    $sale->setRelation('bundle', $bundle);
                }
            }
            $sale->created_at = time();
        } else {
                // Load relationships if not already loaded
                if (!$sale->relationLoaded('webinar') && !empty($sale->webinar_id)) {
                    $sale->load('webinar');
                }
                if (!$sale->relationLoaded('bundle') && !empty($sale->bundle_id)) {
                    $sale->load('bundle');
                }
        }
        
        // Only add if not already in collection (avoid duplicates)
        $exists = $expiredSales->contains(function($existingSale) use ($itemId, $itemName) {
            if ($itemName == 'webinar_id') {
                return !empty($existingSale->webinar_id) && $existingSale->webinar_id == $itemId;
            } else {
                return !empty($existingSale->bundle_id) && $existingSale->bundle_id == $itemId;
            }
        });
        
        if (!$exists) {
            $expiredSales->push($sale);
            $expiredPurchasesCount++;
            
            \Log::info('Found expired subscription purchase', [
                'user_id' => $user->id,
                'item_id' => $itemId,
                'item_name' => $itemName
            ]);
        }
    }

    public function resubscribeAllExpired(Request $request)
    {
        $this->authorize("panel_webinars_my_purchases");

        $user = auth()->user();

        // Get all active subscriptions (user may have multiple)
        $activeSubscribes = \App\Models\Subscribe::getActiveSubscribes($user->id);

        if ($activeSubscribes->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => trans('site.you_dont_have_active_subscribe')
            ], 400);
        }

        // Get all expired subscription-based purchases
        // Find expired subjects from SubscribeUse records (even if subject purchase sales don't exist)
        // Use the same logic as purchases() method - group by item and check if ALL active uses are expired
        $expiredSales = collect();
        
        // Get all SubscribeUse records for webinars and bundles
        $allSubscribeUses = \App\Models\SubscribeUse::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNotNull('webinar_id')
                    ->orWhereNotNull('bundle_id');
            })
            ->with(['sale'])
            ->get();

        \Log::info('Resubscribe: Checking for expired purchases', [
            'user_id' => $user->id,
            'total_subscribe_uses' => $allSubscribeUses->count()
        ]);

        // Group SubscribeUse records by item to check if ALL active uses are expired
        $usesByItem = [];
        foreach ($allSubscribeUses as $use) {
            $itemId = !empty($use->webinar_id) ? $use->webinar_id : $use->bundle_id;
            $itemName = !empty($use->webinar_id) ? 'webinar_id' : 'bundle_id';
            
            if (empty($itemId)) {
                continue;
            }
            
            $key = $itemName . '_' . $itemId;
            if (!isset($usesByItem[$key])) {
                $usesByItem[$key] = [
                    'item_id' => $itemId,
                    'item_name' => $itemName,
                    'uses' => []
                ];
            }
            $usesByItem[$key]['uses'][] = $use;
        }
        
        // Check each item to see if ALL active uses have expired subscriptions (or no active uses)
        foreach ($usesByItem as $itemData) {
            $itemId = $itemData['item_id'];
            $itemName = $itemData['item_name'];
            $uses = $itemData['uses'];
            
            // Find all active SubscribeUse records for this item
            $activeUses = [];
            foreach ($uses as $use) {
                $isUseActive = $use->active && (is_null($use->expired_at) || $use->expired_at > time());
                if ($isUseActive) {
                    $activeUses[] = $use;
                }
            }
            
            // If no active uses, subject is expired
            if (empty($activeUses)) {
                // Subject has no active uses - mark as expired
                $sale = Sale::where('buyer_id', $user->id)
                    ->where('payment_method', Sale::$subscribe)
                ->where($itemName, $itemId)
                    ->whereNull('refund_at')
                    ->first();
                
                // If no sale exists, create a virtual sale object
                if (!$sale) {
                    $sale = new Sale();
                    $sale->id = 0; // Virtual sale
                    $sale->buyer_id = $user->id;
                    $sale->payment_method = Sale::$subscribe;
                    $sale->type = $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle;
                    if ($itemName == 'webinar_id') {
                        $sale->webinar_id = $itemId;
                        $webinar = Webinar::find($itemId);
                        if ($webinar) {
                            $sale->setRelation('webinar', $webinar);
                        }
                    } else {
                        $sale->bundle_id = $itemId;
                        $bundle = \App\Models\Bundle::find($itemId);
                        if ($bundle) {
                            $sale->setRelation('bundle', $bundle);
                        }
                    }
                    $sale->created_at = !empty($uses[0]) && $uses[0]->sale ? $uses[0]->sale->created_at : time();
                } else {
                    if (!$sale->relationLoaded('webinar') && !empty($sale->webinar_id)) {
                        $sale->load('webinar');
                    }
                    if (!$sale->relationLoaded('bundle') && !empty($sale->bundle_id)) {
                        $sale->load('bundle');
                    }
                }
                
                $item = !empty($sale->webinar) ? $sale->webinar : $sale->bundle;
                if ($item && $item->subscribe) {
                    // Only add if not already in collection (avoid duplicates)
                    $exists = $expiredSales->contains(function($existingSale) use ($itemId, $itemName) {
                        if ($itemName == 'webinar_id') {
                            return !empty($existingSale->webinar_id) && $existingSale->webinar_id == $itemId;
                        } else {
                            return !empty($existingSale->bundle_id) && $existingSale->bundle_id == $itemId;
                        }
                    });
                    
                    if (!$exists) {
                        $expiredSales->push($sale);
                        
                        \Log::info('Resubscribe: Found expired purchase (no active uses)', [
                    'user_id' => $user->id,
                    'item_id' => $itemId,
                    'item_name' => $itemName,
                    'item_title' => $item->title ?? 'N/A'
                ]);
                    }
                }
                continue;
            }
            
            // Check if ALL active uses have expired subscriptions
            $allExpired = true;
            foreach ($activeUses as $use) {
                $subscribeSale = Sale::where('buyer_id', $user->id)
                    ->where('type', Sale::$subscribe)
                    ->where('subscribe_id', $use->subscribe_id)
                    ->whereNull('refund_at')
                    ->latest('created_at')
                    ->first();
                
                if ($subscribeSale && $subscribeSale->subscribe) {
                    $daysSincePurchase = (int)diffTimestampDay(time(), $subscribeSale->created_at);
                    $isSubscriptionExpired = $subscribeSale->subscribe->days > 0 && 
                                           $subscribeSale->subscribe->days <= $daysSincePurchase;
                    
                    // If at least one active use has a non-expired subscription, subject is not expired
                    if (!$isSubscriptionExpired) {
                        $allExpired = false;
                        break;
                    }
                } else {
                    // No subscription sale found - assume not expired
                    $allExpired = false;
                    break;
                }
            }
            
            // Only mark as expired if ALL active uses have expired subscriptions
            if ($allExpired) {
                $sale = Sale::where('buyer_id', $user->id)
                    ->where('payment_method', Sale::$subscribe)
                    ->where($itemName, $itemId)
                    ->whereNull('refund_at')
                    ->first();
                
                // If no sale exists, create a virtual sale object
                if (!$sale) {
                    $sale = new Sale();
                    $sale->id = 0; // Virtual sale
                    $sale->buyer_id = $user->id;
                    $sale->payment_method = Sale::$subscribe;
                    $sale->type = $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle;
                    if ($itemName == 'webinar_id') {
                        $sale->webinar_id = $itemId;
                        $webinar = Webinar::find($itemId);
                        if ($webinar) {
                            $sale->setRelation('webinar', $webinar);
                        }
                    } else {
                        $sale->bundle_id = $itemId;
                        $bundle = \App\Models\Bundle::find($itemId);
                        if ($bundle) {
                            $sale->setRelation('bundle', $bundle);
                        }
                    }
                    $sale->created_at = !empty($uses[0]) && $uses[0]->sale ? $uses[0]->sale->created_at : time();
                } else {
                    if (!$sale->relationLoaded('webinar') && !empty($sale->webinar_id)) {
                        $sale->load('webinar');
                    }
                    if (!$sale->relationLoaded('bundle') && !empty($sale->bundle_id)) {
                        $sale->load('bundle');
                    }
                }
                
                $item = !empty($sale->webinar) ? $sale->webinar : $sale->bundle;
                if ($item && $item->subscribe) {
                    // Only add if not already in collection (avoid duplicates)
                    $exists = $expiredSales->contains(function($existingSale) use ($itemId, $itemName) {
                        if ($itemName == 'webinar_id') {
                            return !empty($existingSale->webinar_id) && $existingSale->webinar_id == $itemId;
                        } else {
                            return !empty($existingSale->bundle_id) && $existingSale->bundle_id == $itemId;
                        }
                    });
                    
                    if (!$exists) {
                        $expiredSales->push($sale);
                        
                        \Log::info('Resubscribe: Found expired purchase (all active uses expired)', [
                            'user_id' => $user->id,
                            'item_id' => $itemId,
                            'item_name' => $itemName,
                            'item_title' => $item->title ?? 'N/A'
                        ]);
                    }
                }
            }
        }

        \Log::info('Resubscribe: Expired purchases count', [
            'user_id' => $user->id,
            'expired_count' => $expiredSales->count()
        ]);

        if ($expiredSales->isEmpty()) {
            return response()->json([
                'status' => 'info',
                'message' => trans('panel.no_expired_purchases')
            ], 200);
        }

        $resubscribedCount = 0;
        $errors = [];

        foreach ($expiredSales as $sale) {
            $item = !empty($sale->webinar) ? $sale->webinar : $sale->bundle;
            if (empty($item)) {
                continue;
            }

            $itemId = !empty($sale->webinar_id) ? $sale->webinar_id : $sale->bundle_id;
            $itemName = !empty($sale->webinar_id) ? 'webinar_id' : 'bundle_id';

            // Check if item is subscribable
            if (!$item->subscribe) {
                $errors[] = trans('api.course.not_subscribable') . ': ' . $item->title;
                continue;
            }

            // Find the best subscription that can be used for this item
            // Priority: 1) Category match, 2) Most remaining slots, 3) Longest remaining days
            $bestSubscribe = null;
            $bestScore = -1;
            
            foreach ($activeSubscribes as $subscribe) {
                // Check if subscription has category restrictions
                $categoryAllowed = true;
                if ($subscribe->categories && $subscribe->categories->count() > 0) {
                    $allowedCategoryIds = $subscribe->categories->pluck('id')->toArray();
                    if (!in_array($item->category_id, $allowedCategoryIds)) {
                        $categoryAllowed = false;
                    }
                }

                /**
                 * Determine whether this active subscription is backed by a Sale or an InstallmentOrder.
                 * getActiveSubscribes() sets:
                 * - sale_id for regular subscription purchases
                 * - installment_order_id for installment-based subscriptions
                 *
                 * IMPORTANT: If sale_id is null and we query SubscribeUse::where('sale_id', null),
                 * we may accidentally count unrelated rows and incorrectly treat the plan as "full".
                 */
                $subscribeSaleId = $subscribe->sale_id ?? null;
                $installmentOrderId = $subscribe->installment_order_id ?? null;

                // Fallback: if this isn't an installment subscription and sale_id wasn't populated, resolve latest non-refunded sale
                if (empty($installmentOrderId) && empty($subscribeSaleId)) {
                    $subscribeSale = Sale::where('buyer_id', $user->id)
                        ->where('type', Sale::$subscribe)
                        ->where('subscribe_id', $subscribe->id)
                        ->whereNull('refund_at')
                        ->latest('created_at')
                        ->first();

                    if ($subscribeSale) {
                        $subscribeSaleId = $subscribeSale->id;
                    }
                }

                // If we still have no backing record, skip (can't safely count uses or create uses)
                if (empty($installmentOrderId) && empty($subscribeSaleId)) {
                    continue;
                }

                // Recalculate use count for this specific sale/installment to ensure accuracy (cache might be stale)
                $useCountQueryBase = \App\Models\SubscribeUse::query()
                    ->where('active', true)
                    ->where(function($query) {
                        $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                    });

                if (!empty($installmentOrderId)) {
                    $useCountQueryBase->where('installment_order_id', $installmentOrderId);
                } else {
                    $useCountQueryBase->where('sale_id', $subscribeSaleId);
                }

                $uniqueWebinarIds = (clone $useCountQueryBase)
                    ->whereNotNull('webinar_id')
                    ->distinct()
                    ->pluck('webinar_id')
                    ->count();

                $uniqueBundleIds = (clone $useCountQueryBase)
                    ->whereNotNull('bundle_id')
                    ->distinct()
                    ->pluck('bundle_id')
                    ->count();

                $currentUseCount = $uniqueWebinarIds + $uniqueBundleIds;

                // Check if this item is already active in this subscription context (sale/installment).
                // If it is, we don't need a new slot (we're just updating the subscription link)
                $existingActiveUseQuery = \App\Models\SubscribeUse::query()
                    ->where($itemName, $itemId)
                    ->where('active', true)
                    ->where(function($query) {
                        $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                    });

                if (!empty($installmentOrderId)) {
                    $existingActiveUseQuery->where('installment_order_id', $installmentOrderId);
                } else {
                    $existingActiveUseQuery->where('sale_id', $subscribeSaleId);
                }

                $existingActiveUse = $existingActiveUseQuery->first();
                
                if ($existingActiveUse) {
                    // Item is already active in this subscription, allow it (we'll just update the link)
                    $hasAvailableSlots = true;
                } else {
                    // Item is not active (expired or never subscribed), check if there are available slots
                    // Resubscribing consumes a slot just like subscribing to a new subject
                    $hasAvailableSlots = $subscribe->infinite_use || $currentUseCount < $subscribe->usable_count;
                }

                if ($categoryAllowed && $hasAvailableSlots) {
                    // Calculate score: prioritize subscriptions with most remaining slots
                    $remainingSlots = $subscribe->infinite_use ? 999999 : ($subscribe->usable_count - $currentUseCount);
                    $daysRemaining = isset($subscribe->days_remaining) ? $subscribe->days_remaining : $subscribe->days;
                    $score = $remainingSlots * 1000 + $daysRemaining;
                    
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestSubscribe = $subscribe;
                        $bestSubscribe->selected_sale_id = $subscribeSaleId;
                        $bestSubscribe->selected_installment_order_id = $installmentOrderId;
                    }
                }
            }

            // If no suitable subscription found, skip this item
            if (!$bestSubscribe) {
                $errors[] = trans('site.subscription_limit_reached') . ': ' . $item->title;
                \Log::warning('Resubscribe: No available subscription for item', [
                    'user_id' => $user->id,
                    'item_id' => $itemId,
                    'item_title' => $item->title
                ]);
                continue;
            }

            $activeSubscribe = $bestSubscribe;
            $subscriptionSaleId = $bestSubscribe->selected_sale_id;
            $subscriptionInstallmentOrderId = $bestSubscribe->selected_installment_order_id;

            // Check if there's already an active use for this item with a non-expired subscription plan
            $existingUse = \App\Models\SubscribeUse::where('user_id', $user->id)
                ->where($itemName, $itemId)
                ->where('active', true)
                ->where(function($query) {
                    $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                })
                ->whereHas('sale', function($query) {
                    $query->whereNull('refund_at');
                })
                ->first();

            if ($existingUse) {
                // Check if the subscription plan behind this use is still active (not expired)
                $existingSubscribeSale = Sale::where('buyer_id', $user->id)
                    ->where('type', Sale::$subscribe)
                    ->where('subscribe_id', $existingUse->subscribe_id)
                    ->whereNull('refund_at')
                    ->latest('created_at')
                    ->first();
                
                if ($existingSubscribeSale && $existingSubscribeSale->subscribe) {
                    $daysSincePurchase = (int)diffTimestampDay(time(), $existingSubscribeSale->created_at);
                    $isSubscriptionExpired = $existingSubscribeSale->subscribe->days > 0 && 
                                           $existingSubscribeSale->subscribe->days <= $daysSincePurchase;
                    
                    // Only skip if subscription plan is still active (not expired)
                    if (!$isSubscriptionExpired) {
                // Already has active subscription for this item
                continue;
                    }
                    // If subscription is expired, we'll resubscribe it below
                } else {
                    // Already has active subscription for this item (no expiration check needed)
                    continue;
                }
            }

            // Final category validation before creating/reactivating SubscribeUse
            if (!\App\Models\Subscribe::checkCategoryMatch($activeSubscribe, $item)) {
                $errors[] = trans('site.subscription_category_mismatch') . ': ' . $item->title;
                \Log::warning('Resubscribe: Category mismatch prevented SubscribeUse creation/reactivation', [
                    'user_id' => $user->id,
                    'item_id' => $itemId,
                    'item_title' => $item->title,
                    'item_category_id' => $item->category_id ?? null,
                    'subscribe_id' => $activeSubscribe->id,
                    'allowed_categories' => $activeSubscribe->categories ? $activeSubscribe->categories->pluck('id')->toArray() : []
                ]);
                continue;
            }

            // Check if there's an expired use for this item (we'll reactivate it if found)
            $expiredUse = \App\Models\SubscribeUse::where('user_id', $user->id)
                ->where($itemName, $itemId)
                ->where('active', false)
                ->first();

            if ($expiredUse) {
                // Check if the expired use is from a canceled course
                // If the Sale record has access_to_purchased_item = false, it was canceled by admin
                $wasCanceled = false;
                if (!empty($expiredUse->sale_id)) {
                    $saleRecord = Sale::find($expiredUse->sale_id);
                    if ($saleRecord && !$saleRecord->access_to_purchased_item) {
                        $wasCanceled = true;
                    }
                } else {
                    // Also check if there's a Sale record for this item that was canceled
                    $canceledSale = Sale::where('buyer_id', $user->id)
                        ->where($itemName, $itemId)
                        ->where('access_to_purchased_item', false)
                        ->whereNull('refund_at')
                        ->first();
                    if ($canceledSale) {
                        $wasCanceled = true;
                    }
                }
                
                if ($wasCanceled) {
                    // Course was canceled by admin - create a NEW SubscribeUse record instead of reactivating
                    \App\Models\SubscribeUse::create(array_filter([
                        'user_id' => $user->id,
                        'subscribe_id' => $activeSubscribe->id,
                        $itemName => $itemId,
                        'sale_id' => $subscriptionSaleId,
                        'installment_order_id' => $subscriptionInstallmentOrderId,
                        'active' => true,
                        'created_at' => time(),
                    ], function($v) { return !is_null($v); }));
                    $resubscribedCount++;
                    \Log::info('Resubscribe: Created new SubscribeUse (course was canceled by admin)', [
                        'user_id' => $user->id,
                        'item_id' => $itemId,
                        'item_name' => $itemName,
                        'subscribe_id' => $activeSubscribe->id,
                        'sale_id' => $subscriptionSaleId,
                        'installment_order_id' => $subscriptionInstallmentOrderId,
                        'old_subscribe_use_id' => $expiredUse->id
                    ]);
                } else {
                    // Not canceled - reactivate the expired use and link it to the best available subscription
                    $expiredUse->active = true;
                    $expiredUse->expired_at = null;
                    $expiredUse->subscribe_id = $activeSubscribe->id;
                    $expiredUse->sale_id = $subscriptionSaleId;
                    $expiredUse->installment_order_id = $subscriptionInstallmentOrderId;
                    $expiredUse->save();
                    $resubscribedCount++;
                    \Log::info('Resubscribe: Reactivated expired SubscribeUse', [
                        'user_id' => $user->id,
                        'item_id' => $itemId,
                        'item_name' => $itemName,
                        'subscribe_id' => $activeSubscribe->id,
                        'sale_id' => $subscriptionSaleId,
                        'installment_order_id' => $subscriptionInstallmentOrderId,
                    ]);
                }
            } else {
                // Check if there's an active use linked to an expired subscription plan
                // We'll update it to link to the new active subscription
                $activeUseWithExpiredSubscription = \App\Models\SubscribeUse::where('user_id', $user->id)
                    ->where($itemName, $itemId)
                    ->where('active', true)
                    ->where(function($query) {
                        $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                    })
                    ->first();
                
                if ($activeUseWithExpiredSubscription) {
                    // Check if the subscription plan is expired
                    $oldSubscribeSale = Sale::where('buyer_id', $user->id)
                        ->where('type', Sale::$subscribe)
                        ->where('subscribe_id', $activeUseWithExpiredSubscription->subscribe_id)
                        ->whereNull('refund_at')
                        ->latest('created_at')
                        ->first();
                    
                    if ($oldSubscribeSale && $oldSubscribeSale->subscribe) {
                        $daysSincePurchase = (int)diffTimestampDay(time(), $oldSubscribeSale->created_at);
                        $isSubscriptionExpired = $oldSubscribeSale->subscribe->days > 0 && 
                                               $oldSubscribeSale->subscribe->days <= $daysSincePurchase;
                        
                        if ($isSubscriptionExpired) {
                            // Update the existing use to link to the new active subscription
                            $activeUseWithExpiredSubscription->subscribe_id = $activeSubscribe->id;
                            $activeUseWithExpiredSubscription->sale_id = $subscriptionSaleId;
                            $activeUseWithExpiredSubscription->installment_order_id = $subscriptionInstallmentOrderId;
                            $activeUseWithExpiredSubscription->expired_at = null;
                            $activeUseWithExpiredSubscription->save();
                            $resubscribedCount++;
                            \Log::info('Resubscribe: Updated active SubscribeUse to new subscription', [
                                'user_id' => $user->id,
                                'item_id' => $itemId,
                                'item_name' => $itemName,
                                'old_subscribe_id' => $oldSubscribeSale->subscribe_id,
                                'new_subscribe_id' => $activeSubscribe->id,
                                'new_sale_id' => $subscriptionSaleId,
                                'new_installment_order_id' => $subscriptionInstallmentOrderId,
                            ]);
                        } else {
                            // Subscription is still active, skip
                            continue;
                        }
                    } else {
                        // No subscription sale found, update to new subscription anyway
                        $activeUseWithExpiredSubscription->subscribe_id = $activeSubscribe->id;
                        $activeUseWithExpiredSubscription->sale_id = $subscriptionSaleId;
                        $activeUseWithExpiredSubscription->installment_order_id = $subscriptionInstallmentOrderId;
                        $activeUseWithExpiredSubscription->expired_at = null;
                        $activeUseWithExpiredSubscription->save();
                        $resubscribedCount++;
                        \Log::info('Resubscribe: Updated SubscribeUse to new subscription (no old sale found)', [
                            'user_id' => $user->id,
                            'item_id' => $itemId,
                            'item_name' => $itemName,
                            'new_subscribe_id' => $activeSubscribe->id,
                            'new_sale_id' => $subscriptionSaleId,
                            'new_installment_order_id' => $subscriptionInstallmentOrderId,
                        ]);
                    }
            } else {
                // Create new SubscribeUse using the selected subscription
                \App\Models\SubscribeUse::create(array_filter([
                    'user_id' => $user->id,
                    'subscribe_id' => $activeSubscribe->id,
                    $itemName => $itemId,
                    'sale_id' => $subscriptionSaleId,
                    'installment_order_id' => $subscriptionInstallmentOrderId,
                    'active' => true,
                    'created_at' => time(),
                ], function($v) { return !is_null($v); }));
                $resubscribedCount++;
                \Log::info('Resubscribe: Created new SubscribeUse', [
                    'user_id' => $user->id,
                    'item_id' => $itemId,
                    'item_name' => $itemName,
                    'subscribe_id' => $activeSubscribe->id,
                    'sale_id' => $subscriptionSaleId,
                    'installment_order_id' => $subscriptionInstallmentOrderId,
                ]);
                }
            }
            
            // Ensure a Sale record exists for this item purchase (required for purchases page)
            // Check if a Sale record already exists for this item
            $existingSale = Sale::where('buyer_id', $user->id)
                ->where($itemName, $itemId)
                ->where('type', $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle)
                ->where('payment_method', Sale::$subscribe)
                ->first();
            
            if (!$existingSale) {
                // Create a Sale record for this subscription purchase
                $itemSale = Sale::create([
                    'buyer_id' => $user->id,
                    'seller_id' => $item->creator_id,
                    $itemName => $itemId,
                    'subscribe_id' => $activeSubscribe->id,
                    'type' => $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle,
                    'payment_method' => Sale::$subscribe,
                    'amount' => 0,
                    'total_amount' => 0,
                    'access_to_purchased_item' => true,
                    'created_at' => time(),
                ]);
                
                // Create accounting entry for the subscription purchase
                \App\Models\Accounting::createAccountingForSaleWithSubscribe($item, $activeSubscribe, $itemName);
                
                \Log::info('Resubscribe: Created Sale record for item (all expired)', [
                    'user_id' => $user->id,
                    'item_id' => $itemId,
                    'item_name' => $itemName,
                    'sale_id' => $itemSale->id,
                    'subscribe_id' => $activeSubscribe->id
                ]);
            } else {
                // Ensure the existing sale has access enabled
                if (!$existingSale->access_to_purchased_item) {
                    $existingSale->access_to_purchased_item = true;
                    $existingSale->save();
                }
            }
        }

        // Clear cache for purchased courses to update dashboard and sidebar counts
        if ($resubscribedCount > 0) {
            // Clear cache for all subscriptions that were used
            $usedSubscribeIds = [];
            foreach ($expiredSales as $sale) {
                $itemId = !empty($sale->webinar_id) ? $sale->webinar_id : $sale->bundle_id;
                $itemName = !empty($sale->webinar_id) ? 'webinar_id' : 'bundle_id';
                
                // Find which subscription was used for this item
                // Note: subscribe_uses table doesn't have created_at column, so we order by id instead
                $use = \App\Models\SubscribeUse::where('user_id', $user->id)
                    ->where($itemName, $itemId)
                    ->where('active', true)
                    ->latest('id')
                    ->first();
                
                if ($use && $use->subscribe_id) {
                    $usedSubscribeIds[] = $use->subscribe_id;
                }
            }
            
            // Clear cache for all used subscriptions
            foreach (array_unique($usedSubscribeIds) as $subscribeId) {
                \App\Models\Subscribe::clearSubscriptionCache($user->id, $subscribeId);
            }
            
            // Also clear general purchase caches
            $cacheKeys = [
                "purchased_courses_ids_{$user->id}",
                "user_purchased_courses_with_active_subscriptions_{$user->id}",
                "all_purchased_webinars_ids_{$user->id}",
            ];
            foreach ($cacheKeys as $key) {
                \Cache::forget($key);
            }
        }

        if ($resubscribedCount > 0) {
            $message = trans('panel.resubscribe_success_count', ['count' => $resubscribedCount]);
            if (!empty($errors)) {
                $message .= ' ' . trans('panel.some_items_could_not_be_resubscribed');
            }
        } else {
            $message = trans('panel.no_items_could_be_resubscribed');
            if (!empty($errors)) {
                $message .= ': ' . implode(', ', array_slice($errors, 0, 3));
            }
        }

        return response()->json([
            'status' => $resubscribedCount > 0 ? 'success' : 'error',
            'message' => $message,
            'count' => $resubscribedCount,
            'errors' => $errors
        ], 200);
    }

    public function resubscribeSingle(Request $request)
    {
        $this->authorize("panel_webinars_my_purchases");

        $user = auth()->user();
        $webinarId = $request->get('webinar_id');
        $bundleId = $request->get('bundle_id');

        if (empty($webinarId) && empty($bundleId)) {
            return response()->json([
                'status' => 'error',
                'message' => trans('site.not_found')
            ], 400);
        }

        // Get all active subscriptions
        $activeSubscribes = \App\Models\Subscribe::getActiveSubscribes($user->id);

        if ($activeSubscribes->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => trans('site.you_dont_have_active_subscribe')
            ], 400);
        }

        $itemId = !empty($webinarId) ? $webinarId : $bundleId;
        $itemName = !empty($webinarId) ? 'webinar_id' : 'bundle_id';
        
        // Get the item
        if (!empty($webinarId)) {
            $item = Webinar::find($webinarId);
        } else {
            $item = \App\Models\Bundle::find($bundleId);
        }

        if (empty($item)) {
            return response()->json([
                'status' => 'error',
                'message' => trans('site.not_found')
            ], 404);
        }

        // Check if there's an expired use for this item (we'll reactivate it later if found)
        $expiredUse = \App\Models\SubscribeUse::where('user_id', $user->id)
            ->where($itemName, $itemId)
            ->where('active', false)
            ->first();

        // Find the best subscription for this item
        $bestSubscribe = null;
        $bestScore = -1;
        $subscriptionSaleId = null;
        $subscriptionInstallmentOrderId = null;

        foreach ($activeSubscribes as $subscribe) {
            // Check if this is an installment-based subscription
            $installmentOrderId = $subscribe->installment_order_id ?? null;
            $subscribeSale = null;
            $subscribeSaleId = null;
            
            if ($installmentOrderId) {
                // Installment-based subscription - use installment_order_id
                $subscribeSaleId = null; // Not applicable for installments
            } else {
                // Sale-based subscription - get the LATEST non-refunded subscription sale
                // This is critical when a subscription was refunded and repurchased
                // We need to use the NEW sale, not the old refunded one
                $subscribeSale = Sale::where('buyer_id', $user->id)
                    ->where('type', Sale::$subscribe)
                    ->where('subscribe_id', $subscribe->id)
                    ->whereNull('refund_at')
                    ->latest('created_at')
                    ->first();
                
                if (empty($subscribeSale)) {
                    continue;
                }
                
                $subscribeSaleId = $subscribeSale->id;
            }
            
            // Ensure we have the correct usable_count - reload subscription if needed
            if (empty($subscribe->usable_count) || $subscribe->usable_count <= 0) {
                $subscribe = \App\Models\Subscribe::find($subscribe->id);
                if (!$subscribe) {
                    continue;
                }
            }
            
            // Recalculate used_count for this specific sale/installment to ensure accuracy
            // This is important because getActiveSubscribes might have cached an old value
            if ($installmentOrderId) {
                // Count usage by installment_order_id
                $uniqueWebinarIds = \App\Models\SubscribeUse::where('installment_order_id', $installmentOrderId)
                    ->where('active', true)
                    ->where(function($query) {
                        $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                    })
                    ->whereNotNull('webinar_id')
                    ->distinct()
                    ->pluck('webinar_id')
                    ->count();
                
                $uniqueBundleIds = \App\Models\SubscribeUse::where('installment_order_id', $installmentOrderId)
                    ->where('active', true)
                    ->where(function($query) {
                        $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                    })
                    ->whereNotNull('bundle_id')
                    ->distinct()
                    ->pluck('bundle_id')
                    ->count();
            } else {
                // Count usage by sale_id
                $uniqueWebinarIds = \App\Models\SubscribeUse::where('sale_id', $subscribeSaleId)
                    ->where('active', true)
                    ->where(function($query) {
                        $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                    })
                    ->whereNotNull('webinar_id')
                    ->distinct()
                    ->pluck('webinar_id')
                    ->count();
                
                $uniqueBundleIds = \App\Models\SubscribeUse::where('sale_id', $subscribeSaleId)
                    ->where('active', true)
                    ->where(function($query) {
                        $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                    })
                    ->whereNotNull('bundle_id')
                    ->distinct()
                    ->pluck('bundle_id')
                    ->count();
            }
            
            $actualUseCountForThisSale = $uniqueWebinarIds + $uniqueBundleIds;

            // Check category match
            // For resubscription, if there's an expired use, allow it even if category doesn't match
            // because the user already had this subject before (it's just expired)
            $categoryAllowed = true;
            if ($subscribe->categories && $subscribe->categories->count() > 0) {
                $allowedCategoryIds = $subscribe->categories->pluck('id')->toArray();
                if (!in_array($item->category_id, $allowedCategoryIds)) {
                    // If there's an expired use for this item, allow resubscription even with category mismatch
                    // The user already had this subject, so they should be able to resubscribe
                    if (empty($expiredUse)) {
                        $categoryAllowed = false;
                    } else {
                        // Expired use exists - allow resubscription even if category doesn't match
                        $categoryAllowed = true;
                    }
                }
            }

            // Check if this item is already active in this subscription sale/installment
            if ($installmentOrderId) {
                $existingActiveUse = \App\Models\SubscribeUse::where('installment_order_id', $installmentOrderId)
                    ->where($itemName, $itemId)
                    ->where('active', true)
                    ->where(function($query) {
                        $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                    })
                    ->first();
            } else {
                $existingActiveUse = \App\Models\SubscribeUse::where('sale_id', $subscribeSaleId)
                    ->where($itemName, $itemId)
                    ->where('active', true)
                    ->where(function($query) {
                        $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                    })
                    ->first();
            }
            
            // Initialize actualUseCount - will be set in all code paths below
            $actualUseCount = $actualUseCountForThisSale;
            
            if ($existingActiveUse) {
                // Item is already active in this subscription, allow it (we'll just update the link)
                $hasAvailableSlots = true;
            } else {
                // Item is not active - check if there are available slots
                // Check if there's an expired use for this item from a different subscription sale/installment
                // If so, we're moving it to this sale/installment (doesn't consume a new slot)
                if ($installmentOrderId) {
                    $expiredUseFromDifferentSale = $expiredUse && 
                        $expiredUse->installment_order_id != null && 
                        $expiredUse->installment_order_id != $installmentOrderId;
                } else {
                    $expiredUseFromDifferentSale = $expiredUse && 
                        $expiredUse->sale_id != null && 
                        $expiredUse->sale_id != $subscribeSaleId;
                }
                
                if ($expiredUseFromDifferentSale) {
                    // Expired use is from a different sale (likely the old refunded sale)
                    // We're moving it to the new sale, so allow it
                    $hasAvailableSlots = true;
                    $actualUseCount = $actualUseCountForThisSale;
                } else {
                    // No expired use, or expired use is from same sale - check if there are available slots
                    // Resubscribing consumes a slot just like subscribing to a new subject
                    // Use the actual count we just calculated for this specific sale
                    $usableCount = $subscribe->usable_count ?? 0;
                    $usedCount = $actualUseCountForThisSale;
                    
                    // Ensure we have valid values
                    if ($usableCount <= 0 && !$subscribe->infinite_use) {
                        \Log::warning('Resubscribe: Invalid usable_count', [
                            'user_id' => $user->id,
                            'subscribe_id' => $subscribe->id,
                            'usable_count' => $usableCount,
                            'infinite_use' => $subscribe->infinite_use
                        ]);
                    }
                    
                    // Simple check: if infinite use OR used count is less than usable count
                    $hasAvailableSlots = $subscribe->infinite_use || ($usableCount > 0 && $usedCount < $usableCount);
                    $actualUseCount = $usedCount;
                    
                    // Always log the check for debugging
                    \Log::info('Resubscribe: Slot availability check', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'subscribe_id' => $subscribe->id,
                        'subscribe_sale_id' => $subscribeSaleId,
                        'installment_order_id' => $installmentOrderId,
                        'used_count' => $usedCount,
                        'usable_count' => $usableCount,
                        'infinite_use' => $subscribe->infinite_use,
                        'comparison' => "$usedCount < $usableCount = " . ($usedCount < $usableCount ? 'true' : 'false'),
                        'has_available_slots' => $hasAvailableSlots,
                        'expired_use_exists' => !empty($expiredUse),
                        'expired_use_sale_id' => $expiredUse ? $expiredUse->sale_id : null,
                        'expired_use_installment_order_id' => $expiredUse ? $expiredUse->installment_order_id : null
                    ]);
                }
                
                // Log for debugging
                \Log::info('Resubscribe: Checking available slots', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'item_id' => $itemId,
                    'item_name' => $itemName,
                    'item_title' => $item->title ?? 'N/A',
                    'item_category_id' => $item->category_id ?? null,
                    'subscribe_id' => $subscribe->id,
                    'subscribe_sale_id' => $subscribeSaleId,
                    'installment_order_id' => $installmentOrderId,
                    'expired_use_exists' => !empty($expiredUse),
                    'expired_use_sale_id' => $expiredUse ? $expiredUse->sale_id : null,
                    'expired_use_installment_order_id' => $expiredUse ? $expiredUse->installment_order_id : null,
                    'expired_use_same_sale' => $expiredUse && ($installmentOrderId ? ($expiredUse->installment_order_id == $installmentOrderId) : ($expiredUse->sale_id == $subscribeSaleId)),
                    'actual_use_count' => $actualUseCount,
                    'actual_use_count_for_this_sale' => $actualUseCountForThisSale,
                    'used_count_from_subscribe' => $subscribe->used_count ?? 0,
                    'usable_count' => $subscribe->usable_count,
                    'infinite_use' => $subscribe->infinite_use,
                    'has_available_slots' => $hasAvailableSlots,
                    'category_allowed' => $categoryAllowed,
                    'subscribe_categories' => $subscribe->categories ? $subscribe->categories->pluck('id')->toArray() : []
                ]);
            }

            // Log every subscription check for debugging
            \Log::info('Resubscribe: Subscription check result', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'subscribe_id' => $subscribe->id,
                'subscribe_sale_id' => $subscribeSaleId,
                'installment_order_id' => $installmentOrderId,
                'category_allowed' => $categoryAllowed,
                'has_available_slots' => $hasAvailableSlots,
                'actual_use_count' => $actualUseCount,
                'actual_use_count_for_this_sale' => $actualUseCountForThisSale,
                'usable_count' => $subscribe->usable_count,
                'infinite_use' => $subscribe->infinite_use,
                'will_be_selected' => ($categoryAllowed && $hasAvailableSlots)
            ]);
            
            if ($categoryAllowed && $hasAvailableSlots) {
                // Use actual use count for remaining slots calculation
                $remainingSlots = $subscribe->infinite_use ? 999999 : ($subscribe->usable_count - (isset($actualUseCount) ? $actualUseCount : $actualUseCountForThisSale));
                $daysRemaining = isset($subscribe->days_remaining) ? $subscribe->days_remaining : $subscribe->days;
                $score = $remainingSlots * 1000 + $daysRemaining;
                
                \Log::info('Resubscribe: Subscription selected', [
                    'user_id' => $user->id,
                    'subscribe_id' => $subscribe->id,
                    'subscribe_sale_id' => $subscribeSaleId,
                    'installment_order_id' => $installmentOrderId,
                    'score' => $score,
                    'remaining_slots' => $remainingSlots
                ]);
                
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestSubscribe = $subscribe;
                    $subscriptionSaleId = $subscribeSaleId;
                    $subscriptionInstallmentOrderId = $installmentOrderId;
                }
            }
        }

        if (!$bestSubscribe) {
            // Log detailed information for debugging
            \Log::warning('Resubscribe: No suitable subscription found', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'item_id' => $itemId,
                'item_name' => $itemName,
                'active_subscribes_count' => $activeSubscribes->count(),
                'expired_use_exists' => !empty($expiredUse),
                'expired_use_sale_id' => $expiredUse ? $expiredUse->sale_id : null,
                'expired_use_installment_order_id' => $expiredUse ? $expiredUse->installment_order_id : null,
                'subscriptions_checked' => $activeSubscribes->map(function($sub) use ($item, $itemId, $itemName, $user) {
                    $installmentOrderId = $sub->installment_order_id ?? null;
                    $subscribeSaleId = $sub->sale_id ?? null;
                    
                    if (empty($installmentOrderId) && empty($subscribeSaleId)) {
                        $subscribeSale = \App\Models\Sale::where('buyer_id', $user->id)
                            ->where('type', \App\Models\Sale::$subscribe)
                            ->where('subscribe_id', $sub->id)
                            ->whereNull('refund_at')
                            ->latest('created_at')
                            ->first();
                        if ($subscribeSale) {
                            $subscribeSaleId = $subscribeSale->id;
                        }
                    }
                    
                    $categoryAllowed = true;
                    if ($sub->categories && $sub->categories->count() > 0) {
                        $allowedCategoryIds = $sub->categories->pluck('id')->toArray();
                        if (!in_array($item->category_id, $allowedCategoryIds)) {
                            $categoryAllowed = false;
                        }
                    }
                    
                    if ($installmentOrderId) {
                        $uniqueWebinarIds = \App\Models\SubscribeUse::where('installment_order_id', $installmentOrderId)
                            ->where('active', true)
                            ->where(function($query) {
                                $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                            })
                            ->whereNotNull('webinar_id')
                            ->distinct()
                            ->pluck('webinar_id')
                            ->count();
                        
                        $uniqueBundleIds = \App\Models\SubscribeUse::where('installment_order_id', $installmentOrderId)
                            ->where('active', true)
                            ->where(function($query) {
                                $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                            })
                            ->whereNotNull('bundle_id')
                            ->distinct()
                            ->pluck('bundle_id')
                            ->count();
                    } else {
                        $uniqueWebinarIds = \App\Models\SubscribeUse::where('sale_id', $subscribeSaleId)
                            ->where('active', true)
                            ->where(function($query) {
                                $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                            })
                            ->whereNotNull('webinar_id')
                            ->distinct()
                            ->pluck('webinar_id')
                            ->count();
                        
                        $uniqueBundleIds = \App\Models\SubscribeUse::where('sale_id', $subscribeSaleId)
                            ->where('active', true)
                            ->where(function($query) {
                                $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                            })
                            ->whereNotNull('bundle_id')
                            ->distinct()
                            ->pluck('bundle_id')
                            ->count();
                    }
                    
                    $actualUseCount = $uniqueWebinarIds + $uniqueBundleIds;
                    
                    return [
                        'subscribe_id' => $sub->id,
                        'subscribe_sale_id' => $subscribeSaleId,
                        'installment_order_id' => $installmentOrderId,
                        'usable_count' => $sub->usable_count,
                        'used_count' => $sub->used_count ?? 0,
                        'actual_use_count' => $actualUseCount,
                        'infinite_use' => $sub->infinite_use,
                        'category_allowed' => $categoryAllowed,
                        'has_available_slots' => $sub->infinite_use || $actualUseCount < $sub->usable_count
                    ];
                })->toArray()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => trans('site.subscription_limit_reached')
            ], 400);
        }

        // Final category validation
        // For resubscription, if there's an expired use, allow it even if category doesn't match
        // because the user already had this subject before (it's just expired)
        $categoryMatches = \App\Models\Subscribe::checkCategoryMatch($bestSubscribe, $item);
        if (!$categoryMatches && empty($expiredUse)) {
            // Only block if category doesn't match AND there's no expired use
            return response()->json([
                'status' => 'error',
                'message' => trans('site.subscription_category_mismatch')
            ], 400);
        }

        // Use the expired use we found earlier (if any)
        if ($expiredUse) {
            // Check if the expired use is from a canceled course
            // If the Sale record has access_to_purchased_item = false, it was canceled by admin
            $wasCanceled = false;
            if (!empty($expiredUse->sale_id)) {
                $saleRecord = Sale::find($expiredUse->sale_id);
                if ($saleRecord && !$saleRecord->access_to_purchased_item) {
                    $wasCanceled = true;
                }
            } else {
                // Also check if there's a Sale record for this item that was canceled
                $canceledSale = Sale::where('buyer_id', $user->id)
                    ->where($itemName, $itemId)
                    ->where('access_to_purchased_item', false)
                    ->whereNull('refund_at')
                    ->first();
                if ($canceledSale) {
                    $wasCanceled = true;
                }
            }
            
            if ($wasCanceled) {
                // Course was canceled by admin - create a NEW SubscribeUse record instead of reactivating
                $subscribeUseData = [
                    'user_id' => $user->id,
                    'subscribe_id' => $bestSubscribe->id,
                    $itemName => $itemId,
                    'active' => true,
                    'created_at' => time(),
                ];
                
                if ($subscriptionInstallmentOrderId) {
                    $subscribeUseData['installment_order_id'] = $subscriptionInstallmentOrderId;
                    $subscribeUseData['sale_id'] = null;
                } else {
                    $subscribeUseData['sale_id'] = $subscriptionSaleId;
                    $subscribeUseData['installment_order_id'] = null;
                }
                
                \App\Models\SubscribeUse::create($subscribeUseData);
                \Log::info('Resubscribe: Created new SubscribeUse (course was canceled by admin)', [
                    'user_id' => $user->id,
                    'item_id' => $itemId,
                    'item_name' => $itemName,
                    'subscribe_id' => $bestSubscribe->id,
                    'sale_id' => $subscriptionSaleId,
                    'installment_order_id' => $subscriptionInstallmentOrderId,
                    'old_subscribe_use_id' => $expiredUse->id
                ]);
            } else {
                // Not canceled - reactivate the expired use
                $expiredUse->active = true;
                $expiredUse->expired_at = null;
                $expiredUse->subscribe_id = $bestSubscribe->id;
                if ($subscriptionInstallmentOrderId) {
                    $expiredUse->installment_order_id = $subscriptionInstallmentOrderId;
                    $expiredUse->sale_id = null;
                } else {
                    $expiredUse->sale_id = $subscriptionSaleId;
                    $expiredUse->installment_order_id = null;
                }
                $expiredUse->save();
            }
        } else {
            // Check if there's an active use linked to an expired subscription
            $activeUseWithExpiredSubscription = \App\Models\SubscribeUse::where('user_id', $user->id)
                ->where($itemName, $itemId)
                ->where('active', true)
                ->where(function($query) {
                    $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                })
                ->first();

            if ($activeUseWithExpiredSubscription) {
                // Check if the subscription is expired
                $oldSubscribeSale = Sale::where('buyer_id', $user->id)
                    ->where('type', Sale::$subscribe)
                    ->where('subscribe_id', $activeUseWithExpiredSubscription->subscribe_id)
                    ->whereNull('refund_at')
                    ->latest('created_at')
                    ->first();
                
                if ($oldSubscribeSale && $oldSubscribeSale->subscribe) {
                    $daysSincePurchase = (int)diffTimestampDay(time(), $oldSubscribeSale->created_at);
                    $isSubscriptionExpired = $oldSubscribeSale->subscribe->days > 0 && 
                                           $oldSubscribeSale->subscribe->days <= $daysSincePurchase;
                    
                    if ($isSubscriptionExpired) {
                        // Update to new subscription
                        $activeUseWithExpiredSubscription->subscribe_id = $bestSubscribe->id;
                        if ($subscriptionInstallmentOrderId) {
                            $activeUseWithExpiredSubscription->installment_order_id = $subscriptionInstallmentOrderId;
                            $activeUseWithExpiredSubscription->sale_id = null;
                        } else {
                            $activeUseWithExpiredSubscription->sale_id = $subscriptionSaleId;
                            $activeUseWithExpiredSubscription->installment_order_id = null;
                        }
                        $activeUseWithExpiredSubscription->expired_at = null;
                        $activeUseWithExpiredSubscription->save();
                    } else {
                        return response()->json([
                            'status' => 'error',
                            'message' => trans('site.you_already_have_active_subscription_for_this_item')
                        ], 400);
                    }
                } else {
                    // Update to new subscription
                    $activeUseWithExpiredSubscription->subscribe_id = $bestSubscribe->id;
                    if ($subscriptionInstallmentOrderId) {
                        $activeUseWithExpiredSubscription->installment_order_id = $subscriptionInstallmentOrderId;
                        $activeUseWithExpiredSubscription->sale_id = null;
                    } else {
                        $activeUseWithExpiredSubscription->sale_id = $subscriptionSaleId;
                        $activeUseWithExpiredSubscription->installment_order_id = null;
                    }
                    $activeUseWithExpiredSubscription->expired_at = null;
                    $activeUseWithExpiredSubscription->save();
                }
            } else {
                // Create new SubscribeUse
                $subscribeUseData = [
                    'user_id' => $user->id,
                    'subscribe_id' => $bestSubscribe->id,
                    $itemName => $itemId,
                    'active' => true,
                    'created_at' => time(),
                ];
                
                if ($subscriptionInstallmentOrderId) {
                    $subscribeUseData['installment_order_id'] = $subscriptionInstallmentOrderId;
                    $subscribeUseData['sale_id'] = null;
                } else {
                    $subscribeUseData['sale_id'] = $subscriptionSaleId;
                    $subscribeUseData['installment_order_id'] = null;
                }
                
                \App\Models\SubscribeUse::create($subscribeUseData);
            }
        }

        // Ensure a Sale record exists for this item purchase (required for purchases page)
        // Check if a Sale record already exists for this item
        $existingSale = Sale::where('buyer_id', $user->id)
            ->where($itemName, $itemId)
            ->where('type', $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle)
            ->where('payment_method', Sale::$subscribe)
            ->first();
        
        if (!$existingSale) {
            // Create a Sale record for this subscription purchase
            $itemSale = Sale::create([
                'buyer_id' => $user->id,
                'seller_id' => $item->creator_id,
                $itemName => $itemId,
                'subscribe_id' => $bestSubscribe->id,
                'type' => $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle,
                'payment_method' => Sale::$subscribe,
                'amount' => 0,
                'total_amount' => 0,
                'access_to_purchased_item' => true,
                'created_at' => time(),
            ]);
            
            // Create accounting entry for the subscription purchase
            \App\Models\Accounting::createAccountingForSaleWithSubscribe($item, $bestSubscribe, $itemName);
            
            \Log::info('Resubscribe: Created Sale record for item', [
                'user_id' => $user->id,
                'item_id' => $itemId,
                'item_name' => $itemName,
                'sale_id' => $itemSale->id,
                'subscribe_id' => $bestSubscribe->id
            ]);
        } else {
            // Ensure the existing sale has access enabled
            if (!$existingSale->access_to_purchased_item) {
                $existingSale->access_to_purchased_item = true;
                $existingSale->save();
            }
        }

        // Clear all cache for this subscription (including all sales)
        \App\Models\Subscribe::clearSubscriptionCache($user->id, $bestSubscribe->id);
        
        // Also clear general purchase caches
        $cacheKeys = [
            "purchased_courses_ids_{$user->id}",
            "user_purchased_courses_with_active_subscriptions_{$user->id}",
            "all_purchased_webinars_ids_{$user->id}",
        ];
        foreach ($cacheKeys as $key) {
            \Cache::forget($key);
        }

        return response()->json([
            'status' => 'success',
            'message' => trans('panel.resubscribe_success')
        ], 200);
    }

    public function resubscribeSelected(Request $request)
    {
        $this->authorize("panel_webinars_my_purchases");

        $user = auth()->user();
        $selectedItems = $request->get('selected_items', []);

        if (empty($selectedItems) || !is_array($selectedItems)) {
            return response()->json([
                'status' => 'error',
                'message' => trans('panel.no_items_selected')
            ], 400);
        }

        // Get all active subscriptions
        $activeSubscribes = \App\Models\Subscribe::getActiveSubscribes($user->id);

        if ($activeSubscribes->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => trans('site.you_dont_have_active_subscribe')
            ], 400);
        }

        $resubscribedCount = 0;
        $errors = [];

        foreach ($selectedItems as $itemData) {
            $webinarId = !empty($itemData['webinar_id']) ? $itemData['webinar_id'] : null;
            $bundleId = !empty($itemData['bundle_id']) ? $itemData['bundle_id'] : null;

            if (empty($webinarId) && empty($bundleId)) {
                continue;
            }

            $itemId = !empty($webinarId) ? $webinarId : $bundleId;
            $itemName = !empty($webinarId) ? 'webinar_id' : 'bundle_id';
            
            // Get the item
            if (!empty($webinarId)) {
                $item = Webinar::find($webinarId);
            } else {
                $item = \App\Models\Bundle::find($bundleId);
            }

            if (empty($item)) {
                $errors[] = trans('site.not_found') . ': ' . ($webinarId ?? $bundleId);
                continue;
            }

            // Find the best subscription for this item
            $bestSubscribe = null;
            $bestScore = -1;
            $subscriptionSaleId = null;
            $subscriptionInstallmentOrderId = null;

            foreach ($activeSubscribes as $subscribe) {
                // Determine subscription context (sale vs installment)
                $subscribeSaleId = $subscribe->sale_id ?? null;
                $installmentOrderId = $subscribe->installment_order_id ?? null;

                // Fallback to latest non-refunded sale if this is not an installment and sale_id wasn't populated
                if (empty($installmentOrderId) && empty($subscribeSaleId)) {
                    $subscribeSale = Sale::where('buyer_id', $user->id)
                        ->where('type', Sale::$subscribe)
                        ->where('subscribe_id', $subscribe->id)
                        ->whereNull('refund_at')
                        ->latest('created_at')
                        ->first();

                    if ($subscribeSale) {
                        $subscribeSaleId = $subscribeSale->id;
                    }
                }

                // Can't safely use this subscription if we don't know which record backs it
                if (empty($installmentOrderId) && empty($subscribeSaleId)) {
                    continue;
                }

                // Check category match
                $categoryAllowed = true;
                if ($subscribe->categories && $subscribe->categories->count() > 0) {
                    $allowedCategoryIds = $subscribe->categories->pluck('id')->toArray();
                    if (!in_array($item->category_id, $allowedCategoryIds)) {
                        $categoryAllowed = false;
                    }
                }

                // Check available slots
                $useCountQueryBase = \App\Models\SubscribeUse::query()
                    ->where('active', true)
                    ->where(function($query) {
                        $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                    });

                if (!empty($installmentOrderId)) {
                    $useCountQueryBase->where('installment_order_id', $installmentOrderId);
                } else {
                    $useCountQueryBase->where('sale_id', $subscribeSaleId);
                }

                // Count unique subjects (webinars + bundles) rather than raw rows
                $uniqueWebinarIds = (clone $useCountQueryBase)
                    ->whereNotNull('webinar_id')
                    ->distinct()
                    ->pluck('webinar_id')
                    ->count();

                $uniqueBundleIds = (clone $useCountQueryBase)
                    ->whereNotNull('bundle_id')
                    ->distinct()
                    ->pluck('bundle_id')
                    ->count();

                $currentUseCount = $uniqueWebinarIds + $uniqueBundleIds;
                
                $hasAvailableSlots = $subscribe->infinite_use || $currentUseCount < $subscribe->usable_count;

                if ($categoryAllowed && $hasAvailableSlots) {
                    $remainingSlots = $subscribe->infinite_use ? 999999 : ($subscribe->usable_count - $currentUseCount);
                    $daysRemaining = isset($subscribe->days_remaining) ? $subscribe->days_remaining : $subscribe->days;
                    $score = $remainingSlots * 1000 + $daysRemaining;
                    
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestSubscribe = $subscribe;
                        $subscriptionSaleId = $subscribeSaleId;
                        $subscriptionInstallmentOrderId = $installmentOrderId;
                    }
                }
            }

            if (!$bestSubscribe) {
                $errors[] = trans('site.subscription_limit_reached') . ': ' . $item->title;
                continue;
            }

            // Final category validation
            if (!\App\Models\Subscribe::checkCategoryMatch($bestSubscribe, $item)) {
                $errors[] = trans('site.subscription_category_mismatch') . ': ' . $item->title;
                continue;
            }

            // Check if there's an expired use (active=false) or an active use linked to expired subscription
            $expiredUse = \App\Models\SubscribeUse::where('user_id', $user->id)
                ->where($itemName, $itemId)
                ->where('active', false)
                ->first();

            if ($expiredUse) {
                // Check if the expired use is from a canceled course
                // If the Sale record has access_to_purchased_item = false, it was canceled by admin
                $wasCanceled = false;
                if (!empty($expiredUse->sale_id)) {
                    $saleRecord = Sale::find($expiredUse->sale_id);
                    if ($saleRecord && !$saleRecord->access_to_purchased_item) {
                        $wasCanceled = true;
                    }
                } else {
                    // Also check if there's a Sale record for this item that was canceled
                    $canceledSale = Sale::where('buyer_id', $user->id)
                        ->where($itemName, $itemId)
                        ->where('access_to_purchased_item', false)
                        ->whereNull('refund_at')
                        ->first();
                    if ($canceledSale) {
                        $wasCanceled = true;
                    }
                }
                
                if ($wasCanceled) {
                    // Course was canceled by admin - create a NEW SubscribeUse record instead of reactivating
                    \App\Models\SubscribeUse::create(array_filter([
                        'user_id' => $user->id,
                        'subscribe_id' => $bestSubscribe->id,
                        $itemName => $itemId,
                        'sale_id' => $subscriptionSaleId,
                        'installment_order_id' => $subscriptionInstallmentOrderId,
                        'active' => true,
                        'created_at' => time(),
                    ], function($v) { return !is_null($v); }));
                    $resubscribedCount++;
                    \Log::info('Resubscribe: Created new SubscribeUse (course was canceled by admin)', [
                        'user_id' => $user->id,
                        'item_id' => $itemId,
                        'item_name' => $itemName,
                        'subscribe_id' => $bestSubscribe->id,
                        'sale_id' => $subscriptionSaleId,
                        'installment_order_id' => $subscriptionInstallmentOrderId,
                        'old_subscribe_use_id' => $expiredUse->id
                    ]);
                } else {
                    // Not canceled - reactivate the expired use
                    $expiredUse->active = true;
                    $expiredUse->expired_at = null;
                    $expiredUse->subscribe_id = $bestSubscribe->id;
                    $expiredUse->sale_id = $subscriptionSaleId;
                    $expiredUse->installment_order_id = $subscriptionInstallmentOrderId;
                    $expiredUse->save();
                    $resubscribedCount++;
                }
            } else {
                // Check if there's an active use linked to an expired subscription
                $activeUseWithExpiredSubscription = \App\Models\SubscribeUse::where('user_id', $user->id)
                    ->where($itemName, $itemId)
                    ->where('active', true)
                    ->where(function($query) {
                        $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                    })
                    ->first();

                if ($activeUseWithExpiredSubscription) {
                    // Check if the subscription is expired
                    $oldSubscribeSale = Sale::where('buyer_id', $user->id)
                        ->where('type', Sale::$subscribe)
                        ->where('subscribe_id', $activeUseWithExpiredSubscription->subscribe_id)
                        ->whereNull('refund_at')
                        ->latest('created_at')
                        ->first();
                    
                    if ($oldSubscribeSale && $oldSubscribeSale->subscribe) {
                        // Use the same expiration logic as getActiveSubscribes()
                        // Honor custom_expiration_date if set (could be from renewal extension)
                        $daysSincePurchase = (int)diffTimestampDay(time(), $oldSubscribeSale->created_at);
                        $calculatedExpiration = $oldSubscribeSale->created_at + ($oldSubscribeSale->subscribe->days * 86400);
                        $maxReasonableExpiration = $oldSubscribeSale->created_at + (($oldSubscribeSale->subscribe->days * 3) + 7) * 86400;
                        
                        $isSubscriptionExpired = false;
                        if (!empty($oldSubscribeSale->custom_expiration_date)) {
                            if ($oldSubscribeSale->custom_expiration_date > $maxReasonableExpiration) {
                                $effectiveExpiration = $calculatedExpiration;
                            } else {
                                $effectiveExpiration = $oldSubscribeSale->custom_expiration_date;
                            }
                            $isSubscriptionExpired = $effectiveExpiration <= time();
                        } else {
                            $isSubscriptionExpired = $oldSubscribeSale->subscribe->days > 0 && 
                                                   $oldSubscribeSale->subscribe->days <= $daysSincePurchase;
                        }
                        
                        if ($isSubscriptionExpired) {
                            // Update to new subscription
                            $activeUseWithExpiredSubscription->subscribe_id = $bestSubscribe->id;
                            if ($subscriptionInstallmentOrderId) {
                                $activeUseWithExpiredSubscription->installment_order_id = $subscriptionInstallmentOrderId;
                                $activeUseWithExpiredSubscription->sale_id = null;
                            } else {
                                $activeUseWithExpiredSubscription->sale_id = $subscriptionSaleId;
                                $activeUseWithExpiredSubscription->installment_order_id = null;
                            }
                            $activeUseWithExpiredSubscription->expired_at = null;
                            $activeUseWithExpiredSubscription->save();
                            $resubscribedCount++;
                        } else {
                            // Subscription is still active - update SubscribeUse to link to current subscription sale
                            // and ensure Sale record exists and has access
                            // This handles cases where user resubscribes but subscription is still valid
                            $needsUseUpdate = false;
                            if ($subscriptionInstallmentOrderId) {
                                if ($activeUseWithExpiredSubscription->installment_order_id != $subscriptionInstallmentOrderId) {
                                    $activeUseWithExpiredSubscription->installment_order_id = $subscriptionInstallmentOrderId;
                                    $activeUseWithExpiredSubscription->sale_id = null;
                                    $needsUseUpdate = true;
                                }
                            } else {
                                if ($activeUseWithExpiredSubscription->sale_id != $subscriptionSaleId) {
                                    $activeUseWithExpiredSubscription->sale_id = $subscriptionSaleId;
                                    $activeUseWithExpiredSubscription->installment_order_id = null;
                                    $needsUseUpdate = true;
                                }
                            }
                            
                            if ($needsUseUpdate) {
                                $activeUseWithExpiredSubscription->save();
                            }
                            
                            // Ensure Sale record exists and has access
                            $existingSale = Sale::where('buyer_id', $user->id)
                                ->where($itemName, $itemId)
                                ->where('type', $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle)
                                ->where('payment_method', Sale::$subscribe)
                                ->whereNull('refund_at')
                                ->first();
                            
                            if (!$existingSale) {
                                // Create Sale record even though subscription is still active
                                Sale::create([
                                    'buyer_id' => $user->id,
                                    'seller_id' => $item->creator_id ?? null,
                                    $itemName => $itemId,
                                    'subscribe_id' => $bestSubscribe->id,
                                    'type' => $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle,
                                    'payment_method' => Sale::$subscribe,
                                    'amount' => 0,
                                    'total_amount' => 0,
                                    'access_to_purchased_item' => true,
                                    'created_at' => time(),
                                ]);
                                
                                \App\Models\Accounting::createAccountingForSaleWithSubscribe($item, $bestSubscribe, $itemName);
                            } else {
                                // Ensure access is enabled
                                if (!$existingSale->access_to_purchased_item) {
                                    $existingSale->access_to_purchased_item = true;
                                    $existingSale->save();
                                }
                            }
                            $resubscribedCount++;
                        }
                    } else {
                        // Update to new subscription
                        $activeUseWithExpiredSubscription->subscribe_id = $bestSubscribe->id;
                        if ($subscriptionInstallmentOrderId) {
                            $activeUseWithExpiredSubscription->installment_order_id = $subscriptionInstallmentOrderId;
                            $activeUseWithExpiredSubscription->sale_id = null;
                        } else {
                            $activeUseWithExpiredSubscription->sale_id = $subscriptionSaleId;
                            $activeUseWithExpiredSubscription->installment_order_id = null;
                        }
                        $activeUseWithExpiredSubscription->expired_at = null;
                        $activeUseWithExpiredSubscription->save();
                        $resubscribedCount++;
                    }
                } else {
                    // Create new SubscribeUse
                    \App\Models\SubscribeUse::create(array_filter([
                        'user_id' => $user->id,
                        'subscribe_id' => $bestSubscribe->id,
                        $itemName => $itemId,
                        'sale_id' => $subscriptionSaleId,
                        'installment_order_id' => $subscriptionInstallmentOrderId,
                        'active' => true,
                        'created_at' => time(),
                    ], function($v) { return !is_null($v); }));
                    $resubscribedCount++;
                }
            }
            
            // Ensure a Sale record exists for this item purchase (required for purchases page)
            // Check if a Sale record already exists for this item (only non-refunded ones)
            $existingSale = Sale::where('buyer_id', $user->id)
                ->where($itemName, $itemId)
                ->where('type', $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle)
                ->where('payment_method', Sale::$subscribe)
                ->whereNull('refund_at')
                ->first();
            
            if (!$existingSale) {
                // Create a Sale record for this subscription purchase
                $itemSale = Sale::create([
                    'buyer_id' => $user->id,
                    'seller_id' => $item->creator_id ?? null,
                    $itemName => $itemId,
                    'subscribe_id' => $bestSubscribe->id,
                    'type' => $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle,
                    'payment_method' => Sale::$subscribe,
                    'amount' => 0,
                    'total_amount' => 0,
                    'access_to_purchased_item' => true,
                    'created_at' => time(),
                ]);
                
                // Create accounting entry for the subscription purchase
                \App\Models\Accounting::createAccountingForSaleWithSubscribe($item, $bestSubscribe, $itemName);
                
                \Log::info('Resubscribe: Created Sale record for item (bulk)', [
                    'user_id' => $user->id,
                    'item_id' => $itemId,
                    'item_name' => $itemName,
                    'sale_id' => $itemSale->id,
                    'subscribe_id' => $bestSubscribe->id
                ]);
            } else {
                // Ensure the existing sale has access enabled and is not refunded
                $needsUpdate = false;
                if (!$existingSale->access_to_purchased_item) {
                    $existingSale->access_to_purchased_item = true;
                    $needsUpdate = true;
                }
                if (!empty($existingSale->refund_at)) {
                    // If sale was refunded, create a new one
                    $existingSale = null;
                    $itemSale = Sale::create([
                        'buyer_id' => $user->id,
                        'seller_id' => $item->creator_id ?? null,
                        $itemName => $itemId,
                        'subscribe_id' => $bestSubscribe->id,
                        'type' => $itemName == 'webinar_id' ? Sale::$webinar : Sale::$bundle,
                        'payment_method' => Sale::$subscribe,
                        'amount' => 0,
                        'total_amount' => 0,
                        'access_to_purchased_item' => true,
                        'created_at' => time(),
                    ]);
                    
                    \App\Models\Accounting::createAccountingForSaleWithSubscribe($item, $bestSubscribe, $itemName);
                    
                    \Log::info('Resubscribe: Created new Sale record (old one was refunded)', [
                        'user_id' => $user->id,
                        'item_id' => $itemId,
                        'item_name' => $itemName,
                        'sale_id' => $itemSale->id,
                        'subscribe_id' => $bestSubscribe->id
                    ]);
                } elseif ($needsUpdate) {
                    $existingSale->save();
                }
            }
        }

        // Clear cache for purchased courses to update dashboard and sidebar counts
        if ($resubscribedCount > 0) {
            // Clear cache for all subscriptions that were used
            $usedSubscribeIds = [];
            foreach ($selectedItems as $itemData) {
                $webinarId = !empty($itemData['webinar_id']) ? $itemData['webinar_id'] : null;
                $bundleId = !empty($itemData['bundle_id']) ? $itemData['bundle_id'] : null;
                $itemId = !empty($webinarId) ? $webinarId : $bundleId;
                $itemName = !empty($webinarId) ? 'webinar_id' : 'bundle_id';
                
                // Find which subscription was used for this item
                // Note: subscribe_uses table doesn't have created_at column, so we order by id instead
                $use = \App\Models\SubscribeUse::where('user_id', $user->id)
                    ->where($itemName, $itemId)
                    ->where('active', true)
                    ->latest('id')
                    ->first();
                
                if ($use && $use->subscribe_id) {
                    $usedSubscribeIds[] = $use->subscribe_id;
                }
            }
            
            // Clear cache for all used subscriptions
            foreach (array_unique($usedSubscribeIds) as $subscribeId) {
                \App\Models\Subscribe::clearSubscriptionCache($user->id, $subscribeId);
            }
            
            // Also clear general purchase caches
            $cacheKeys = [
                "purchased_courses_ids_{$user->id}",
                "user_purchased_courses_with_active_subscriptions_{$user->id}",
                "all_purchased_webinars_ids_{$user->id}",
            ];
            foreach ($cacheKeys as $key) {
                \Cache::forget($key);
            }
        }

        $message = '';
        if ($resubscribedCount > 0) {
            $message = trans('panel.resubscribe_success_count', ['count' => $resubscribedCount]);
            if (!empty($errors)) {
                $message .= ' ' . trans('panel.some_items_could_not_be_resubscribed');
            }
        } else {
            $message = trans('panel.no_items_could_be_resubscribed');
        }

        return response()->json([
            'status' => $resubscribedCount > 0 ? 'success' : 'error',
            'message' => $message,
            'count' => $resubscribedCount,
            'errors' => $errors
        ], 200);
    }

    public function getJoinInfo(Request $request)
    {
        $data = $request->all();
        if (!empty($data['webinar_id'])) {
            $user = auth()->user();

            // Check if the user has an active subscription that grants access
            $activeSubscribe = \App\Models\Subscribe::getActiveSubscribe($user->id);

            if (!empty($activeSubscribe)) {
                // User has an active subscription, grant access
                $webinar = Webinar::where('status', 'active')
                    ->where('id', $data['webinar_id'])
                    ->first();

                if (!empty($webinar)) {
                    $session = Session::select('id', 'creator_id', 'date', 'link', 'zoom_start_link', 'session_api', 'api_secret')
                        ->where('webinar_id', $webinar->id)
                        ->where('date', '>=', time())
                        ->orderBy('date', 'asc')
                        ->whereDoesntHave('agoraHistory', function ($query) {
                            $query->whereNotNull('end_at');
                        })
                        ->first();

                    if (!empty($session)) {
                        $session->date = dateTimeFormat($session->date, 'Y-m-d H:i', false);

                        $session->link = $session->getJoinLink(true);

                        return response()->json([
                            'code' => 200,
                            'session' => $session
                        ], 200);
                    }
                }
            } else {
                // No active subscription, check for individual purchase
                $checkSale = Sale::where('buyer_id', $user->id)
                    ->where('webinar_id', $data['webinar_id'])
                    ->where('type', 'webinar')
                    ->whereNull('refund_at')
                    ->first();

                if (!empty($checkSale)) {
                     $webinar = Webinar::where('status', 'active')
                    ->where('id', $data['webinar_id'])
                    ->first();

                    if (!empty($webinar)) {
                        $session = Session::select('id', 'creator_id', 'date', 'link', 'zoom_start_link', 'session_api', 'api_secret')
                            ->where('webinar_id', $webinar->id)
                            ->where('date', '>=', time())
                            ->orderBy('date', 'asc')
                            ->whereDoesntHave('agoraHistory', function ($query) {
                                $query->whereNotNull('end_at');
                            })
                            ->first();

                        if (!empty($session)) {
                            $session->date = dateTimeFormat($session->date, 'Y-m-d H:i', false);

                            $session->link = $session->getJoinLink(true);

                            return response()->json([
                                'code' => 200,
                                'session' => $session
                            ], 200);
                        }
                    }
                }
            }
        }

        return response()->json([], 422);
    }

    public function getNextSessionInfo($id)
    {
        $user = auth()->user();

        $webinar = Webinar::where('id', $id)
            ->where(function ($query) use ($user) {
                $query->where(function ($query) use ($user) {
                    $query->where('creator_id', $user->id)
                        ->orWhere('teacher_id', $user->id);
                });

                $query->orWhereHas('webinarPartnerTeacher', function ($query) use ($user) {
                    $query->where('teacher_id', $user->id);
                });
            })->first();

        if (!empty($webinar)) {
            $session = Session::where('webinar_id', $webinar->id)
                ->where('date', '>=', time())
                ->orderBy('date', 'asc')
                ->where('status', Session::$Active)
                ->whereDoesntHave('agoraHistory', function ($query) {
                    $query->whereNotNull('end_at');
                })
                ->first();

            if (!empty($session) and $session->title) {
                $session->date = dateTimeFormat($session->date, 'Y-m-d H:i', false);

                $session->link = $session->getJoinLink(true);

                if (!empty($session->agora_settings)) {
                    $session->agora_settings = json_decode($session->agora_settings);
                }
            }

            $chapters = WebinarChapter::query()
                ->where('user_id', $user->id)
                ->where('webinar_id', $webinar->id)
                ->orderBy('order', 'asc')
                ->get();

            return response()->json([
                'code' => 200,
                'session' => $session,
                'webinar_id' => $webinar->id,
                'chapters' => $chapters
            ], 200);
        }

        return response()->json([], 422);
    }

    public function orderItems(Request $request)
    {
        $user = auth()->user();
        $data = $request->all();

        $validator = Validator::make($data, [
            'items' => 'required',
            'table' => 'required',
        ]);

        if ($validator->fails()) {
            return response([
                'code' => 422,
                'errors' => $validator->errors(),
            ], 422);
        }

        $tableName = $data['table'];
        $itemIds = explode(',', $data['items']);

        if (!is_array($itemIds) and !empty($itemIds)) {
            $itemIds = [$itemIds];
        }

        if (!empty($itemIds) and is_array($itemIds) and count($itemIds)) {
            switch ($tableName) {
                case 'tickets':
                    foreach ($itemIds as $order => $id) {
                        Ticket::where('id', $id)
                            ->where('creator_id', $user->id)
                            ->update(['order' => ($order + 1)]);
                    }
                    break;
                case 'sessions':
                    foreach ($itemIds as $order => $id) {
                        Session::where('id', $id)
                            ->where('creator_id', $user->id)
                            ->update(['order' => ($order + 1)]);
                    }
                    break;
                case 'files':
                    foreach ($itemIds as $order => $id) {
                        File::where('id', $id)
                            ->where('creator_id', $user->id)
                            ->update(['order' => ($order + 1)]);
                    }
                    break;
                case 'text_lessons':
                    foreach ($itemIds as $order => $id) {
                        TextLesson::where('id', $id)
                            ->where('creator_id', $user->id)
                            ->update(['order' => ($order + 1)]);
                    }
                    break;
                case 'prerequisites':
                    $webinarIds = $user->webinars()->pluck('id')->toArray();

                    foreach ($itemIds as $order => $id) {
                        Prerequisite::where('id', $id)
                            ->whereIn('webinar_id', $webinarIds)
                            ->update(['order' => ($order + 1)]);
                    }
                    break;
                case 'faqs':
                    foreach ($itemIds as $order => $id) {
                        Faq::where('id', $id)
                            ->where('creator_id', $user->id)
                            ->update(['order' => ($order + 1)]);
                    }
                    break;
                case 'webinar_chapters':
                    foreach ($itemIds as $order => $id) {
                        WebinarChapter::where('id', $id)
                            ->where('user_id', $user->id)
                            ->update(['order' => ($order + 1)]);
                    }
                    break;
                case 'webinar_chapter_items':
                    foreach ($itemIds as $order => $id) {
                        WebinarChapterItem::where('id', $id)
                            ->where('user_id', $user->id)
                            ->update(['order' => ($order + 1)]);
                    }
                case 'bundle_webinars':
                    foreach ($itemIds as $order => $id) {
                        BundleWebinar::where('id', $id)
                            ->where('creator_id', $user->id)
                            ->update(['order' => ($order + 1)]);
                    }
                    break;

                case 'webinar_extra_descriptions_learning_materials':
                    foreach ($itemIds as $order => $id) {
                        WebinarExtraDescription::where('id', $id)
                            ->where('creator_id', $user->id)
                            ->where('type', 'learning_materials')
                            ->update(['order' => ($order + 1)]);
                    }
                    break;

                case 'webinar_extra_descriptions_company_logos':
                    foreach ($itemIds as $order => $id) {
                        WebinarExtraDescription::where('id', $id)
                            ->where('creator_id', $user->id)
                            ->where('type', 'company_logos')
                            ->update(['order' => ($order + 1)]);
                    }
                    break;

                case 'webinar_extra_descriptions_requirements':
                    foreach ($itemIds as $order => $id) {
                        WebinarExtraDescription::where('id', $id)
                            ->where('creator_id', $user->id)
                            ->where('type', 'requirements')
                            ->update(['order' => ($order + 1)]);
                    }
                    break;

            }
        }

        return response()->json([
            'title' => trans('public.request_success'),
            'msg' => trans('update.items_sorted_successful')
        ]);
    }

    public function getContentItemByLocale(Request $request, $id)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'item_id' => 'required',
            'locale' => 'required',
            'relation' => 'required',
        ]);

        if ($validator->fails()) {
            return response([
                'code' => 422,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = auth()->user();

        $webinar = Webinar::where('id', $id)
            ->where(function ($query) use ($user) {
                $query->where(function ($query) use ($user) {
                    $query->where('creator_id', $user->id)
                        ->orWhere('teacher_id', $user->id);
                });

                $query->orWhereHas('webinarPartnerTeacher', function ($query) use ($user) {
                    $query->where('teacher_id', $user->id);
                });
            })->first();

        if (!empty($webinar)) {

            $itemId = $data['item_id'];
            $locale = $data['locale'];
            $relation = $data['relation'];

            if (!empty($webinar->$relation)) {
                $item = $webinar->$relation->where('id', $itemId)->first();

                if (!empty($item)) {
                    foreach ($item->translatedAttributes as $attribute) {
                        try {
                            $item->$attribute = $item->translate(mb_strtolower($locale))->$attribute;
                        } catch (\Exception $e) {
                            $item->$attribute = null;
                        }
                    }

                    return response()->json([
                        'item' => $item
                    ], 200);
                }
            }
        }

        abort(403);
    }
}
