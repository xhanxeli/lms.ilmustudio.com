<?php

namespace App\Models;

use App\Mixins\Certificate\MakeCertificate;
use App\Models\Traits\CascadeDeletes;
use App\User;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Cviebrock\EloquentSluggable\Services\SlugService;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Jorenvh\Share\ShareFacade;
use Spatie\CalendarLinks\Link;
use Carbon\Carbon;

class Webinar extends Model implements TranslatableContract
{
    use Translatable;
    use Sluggable;
    use CascadeDeletes;

    protected $table = 'webinars';
    public $timestamps = false;
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public $morphsFunctions = ['productBadgeContent', 'relatedCourses', 'deleteRequest'];

    static $active = 'active';
    static $pending = 'pending';
    static $isDraft = 'is_draft';
    static $inactive = 'inactive';

    static $webinar = 'webinar';
    static $course = 'course';
    static $textLesson = 'text_lesson';

    static $statuses = [
        'active', 'pending', 'is_draft', 'inactive'
    ];

    static $videoDemoSource = ['upload', 'youtube', 'vimeo', 'external_link', 'secure_host'];

    public $translatedAttributes = ['title', 'description', 'seo_description'];

    public function getTitleAttribute()
    {
        return getTranslateAttributeValue($this, 'title');
    }

    public function getDescriptionAttribute()
    {
        return getTranslateAttributeValue($this, 'description');
    }

    public function getSeoDescriptionAttribute()
    {
        return getTranslateAttributeValue($this, 'seo_description');
    }

    public function getPriceAttribute()
    {
        $result = $this->attributes['price'] ?? null;

        $user = auth()->user();

        if (!empty($this->attributes['organization_price']) and !empty($user) and $this->creator->isOrganization() and $user->organ_id == $this->creator_id) {
            $result = $this->attributes['organization_price'];
        }

        return $result;
    }

    public function creator()
    {
        return $this->belongsTo('App\User', 'creator_id', 'id');
    }

    public function teacher()
    {
        return $this->belongsTo('App\User', 'teacher_id', 'id');
    }

    public function category()
    {
        return $this->belongsTo('App\Models\Category', 'category_id', 'id');
    }

    public function filterOptions()
    {
        return $this->hasMany('App\Models\WebinarFilterOption', 'webinar_id', 'id');
    }

    public function tickets()
    {
        return $this->hasMany('App\Models\Ticket', 'webinar_id', 'id');
    }


    public function chapters()
    {
        return $this->hasMany('App\Models\WebinarChapter', 'webinar_id', 'id');
    }

    public function sessions()
    {
        return $this->hasMany('App\Models\Session', 'webinar_id', 'id');
    }

    public function files()
    {
        return $this->hasMany('App\Models\File', 'webinar_id', 'id');
    }

    public function assignments()
    {
        return $this->hasMany('App\Models\WebinarAssignment', 'webinar_id', 'id');
    }

    public function textLessons()
    {
        return $this->hasMany('App\Models\TextLesson', 'webinar_id', 'id');
    }

    public function faqs()
    {
        return $this->hasMany('App\Models\Faq', 'webinar_id', 'id');
    }

    public function webinarExtraDescription()
    {
        return $this->hasMany('App\Models\WebinarExtraDescription', 'webinar_id', 'id');
    }

    public function prerequisites()
    {
        return $this->hasMany('App\Models\Prerequisite', 'webinar_id', 'id');
    }

    public function quizzes()
    {
        return $this->hasMany('App\Models\Quiz', 'webinar_id', 'id');
    }

    public function webinarPartnerTeacher()
    {
        return $this->hasMany('App\Models\WebinarPartnerTeacher', 'webinar_id', 'id');
    }

    public function tags()
    {
        return $this->hasMany('App\Models\Tag', 'webinar_id', 'id');
    }

    public function purchases()
    {
        return $this->hasMany('App\Models\Purchase', 'webinar_id', 'id');
    }

    public function comments()
    {
        return $this->hasMany('App\Models\Comment', 'webinar_id', 'id');
    }

    public function reviews()
    {
        return $this->hasMany('App\Models\WebinarReview', 'webinar_id', 'id');
    }

    public function sales()
    {
        return $this->hasMany('App\Models\Sale', 'webinar_id', 'id')
            ->whereNull('refund_at')
            ->where('type', 'webinar');
    }

    public function feature()
    {
        return $this->hasOne('App\Models\FeatureWebinar', 'webinar_id', 'id');
    }

    public function noticeboards()
    {
        return $this->hasMany('App\Models\CourseNoticeboard', 'webinar_id', 'id');
    }

    public function forums()
    {
        return $this->hasMany('App\Models\CourseForum', 'webinar_id', 'id');
    }

    public function productBadgeContent()
    {
        return $this->morphMany(ProductBadgeContent::class, 'targetable');
    }

    public function relatedCourses()
    {
        return $this->morphMany('App\Models\RelatedCourse', 'targetable');
    }

    public function deleteRequest()
    {
        return $this->morphOne(ContentDeleteRequest::class, 'targetable');
    }

    public function waitlists()
    {
        return $this->hasMany(Waitlist::class, 'webinar_id', 'id');
    }


    public function getRate()
    {
        $rate = 0;

        if (!empty($this->avg_rates)) {
            $rate = $this->avg_rates;
        } else {
            $reviews = $this->reviews()
                ->where('status', 'active')
                ->get();

            if (!empty($reviews) and $reviews->count() > 0) {
                $rate = number_format($reviews->avg('rates'), 2);
            }
        }


        if ($rate > 5) {
            $rate = 5;
        }

        return $rate > 0 ? number_format($rate, 2) : 0;
    }

    /**
     * Return the sluggable configuration array for this model.
     *
     * @return array
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'title'
            ]
        ];
    }

    public static function makeSlug($title)
    {
        return SlugService::createSlug(self::class, 'slug', $title);
    }

    public function bestTicket($with_percent = false)
    {
        $ticketPercent = 0;
        $bestTicket = $this->price;

        $activeSpecialOffer = $this->activeSpecialOffer();

        if ($activeSpecialOffer) {
            $bestTicket = $this->price - ($this->price * $activeSpecialOffer->percent / 100);
            $ticketPercent = $activeSpecialOffer->percent;
        } else {
            foreach ($this->tickets as $ticket) {

                if ($ticket->isValid()) {
                    $discount = $this->price - ($this->price * $ticket->discount / 100);

                    if ($bestTicket > $discount) {
                        $bestTicket = $discount;
                        $ticketPercent = $ticket->discount;
                    }
                }
            }
        }

        if ($with_percent) {
            return [
                'bestTicket' => $bestTicket,
                'percent' => $ticketPercent
            ];
        }

        return $bestTicket;
    }

    public function getDiscount($ticket = null, $user = null)
    {
        $activeSpecialOffer = $this->activeSpecialOffer();

        $discountOut = $activeSpecialOffer ? $this->price * $activeSpecialOffer->percent / 100 : 0;

        if (!empty($user) and !empty($user->getUserGroup()) and isset($user->getUserGroup()->discount) and $user->getUserGroup()->discount > 0) {
            $discountOut += $this->price * $user->getUserGroup()->discount / 100;
        }

        if (!empty($ticket) and $ticket->isValid()) {
            $discountOut += $this->price * $ticket->discount / 100;
        }

        return $discountOut;
    }

    public function getDiscountPercent()
    {
        $percent = 0;

        $activeSpecialOffer = $this->activeSpecialOffer();

        if (!empty($activeSpecialOffer)) {
            $percent += $activeSpecialOffer->percent;
        }

        $tickets = Ticket::where('webinar_id', $this->id)->get();

        foreach ($tickets as $ticket) {
            if (!empty($ticket) and $ticket->isValid()) {
                $percent += $ticket->discount;
            }
        }

        return $percent;
    }

    public function getWebinarCapacity()
    {
        $salesCount = !empty($this->sales_count) ? $this->sales_count : $this->sales()->count();

        $capacity = $this->capacity - $salesCount;

        return $capacity > 0 ? $capacity : 0;
    }

    public function getExpiredAccessDays($purchaseDate, $giftId = null)
    {
        if (!empty($giftId)) {
            $gift = Gift::query()->where('id', $giftId)
                ->where('status', 'active')
                ->first();

            if (!empty($gift) and !empty($gift->date)) {
                $purchaseDate = $gift->date;
            }
        }

        return strtotime("+{$this->access_days} days", $purchaseDate);
    }

    public function checkHasExpiredAccessDays($purchaseDate, $giftId = null)
    {
        // true => has access
        // false => not access (expired)

        if (!empty($giftId)) {
            $gift = Gift::query()->where('id', $giftId)
                ->where('status', 'active')
                ->first();

            if (!empty($gift) and !empty($gift->date)) {
                $purchaseDate = $gift->date;
            }
        }

        $time = time();

        return strtotime("+{$this->access_days} days", $purchaseDate) > $time;
    }

    public function getSaleItem($user = null)
    {
        if (empty($user)) {
            $user = auth()->user();
        }

        if (!empty($user)) {
            // Explicitly exclude refunded sales - check both refund_at and access_to_purchased_item
            return Sale::query()->where('buyer_id', $user->id)
                ->where('webinar_id', $this->id)
                ->where('type', 'webinar')
                ->whereNull('refund_at')
                ->where('access_to_purchased_item', true)
                ->orderBy('created_at', 'desc')
                ->first();
        }

        return null;
    }

    public function checkUserHasBought($user = null, $checkExpired = true, $test = false): bool
    {
        $hasBought = false;

        if (empty($user) and auth()->check()) {
            $user = auth()->user();
        }

        if (empty($user)) {
            $user = apiAuth();
        }

        if (!empty($user)) {
            // First, check if the most recent sale for this course is refunded
            // If it is, deny access unless there's a more recent non-refunded sale
            $mostRecentSale = Sale::query()->where('buyer_id', $user->id)
                ->where('webinar_id', $this->id)
                ->where('type', 'webinar')
                ->orderBy('created_at', 'desc')
                ->first();
            
            $hasRefundedSale = false;
            if (!empty($mostRecentSale) && !empty($mostRecentSale->refund_at)) {
                // Check if there's a non-refunded sale that's more recent or equal
                $nonRefundedSale = Sale::query()->where('buyer_id', $user->id)
                    ->where('webinar_id', $this->id)
                    ->where('type', 'webinar')
                    ->whereNull('refund_at')
                    ->where('access_to_purchased_item', true)
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                // If the most recent sale is refunded AND there's no valid non-refunded sale, deny access
                if (empty($nonRefundedSale) || $mostRecentSale->created_at >= $nonRefundedSale->created_at) {
                    $hasRefundedSale = true;
                }
            }
            
            $sale = $this->getSaleItem($user);
            
            // Double-check: if sale exists, verify it's not refunded (extra safeguard)
            if (!empty($sale) && (!empty($sale->refund_at) || !$sale->access_to_purchased_item)) {
                $sale = null;
            }

            if (!empty($sale)) {
                $hasBought = true;

                if ($sale->payment_method == Sale::$subscribe) {
                    $subscribe = $sale->getUsedSubscribe($sale->buyer_id, $sale->webinar_id);

                    if (!empty($subscribe)) {
                        $subscribeSaleCreatedAt = null;

                        if (!empty($subscribe->installment_order_id)) {
                            $installmentOrder = InstallmentOrder::query()->where('user_id', $user->id)
                                ->where('id', $subscribe->installment_order_id)
                                ->where('status', 'open')
                                ->whereNull('refund_at')
                                ->first();

                            if (!empty($installmentOrder)) {
                                $subscribeSaleCreatedAt = $installmentOrder->created_at;

                                if ($installmentOrder->checkOrderHasOverdue()) {
                                    $overdueIntervalDays = getInstallmentsSettings('overdue_interval_days');

                                    if (empty($overdueIntervalDays) or $installmentOrder->overdueDaysPast() > $overdueIntervalDays) {
                                        $hasBought = false;
                                    }
                                }
                            }
                        } else {
                            $subscribeSale = Sale::where('buyer_id', $user->id)
                                ->where('type', Sale::$subscribe)
                                ->where('subscribe_id', $subscribe->id)
                                ->whereNull('refund_at')
                                ->latest('created_at')
                                ->first();

                            if (!empty($subscribeSale)) {
                                $subscribeSaleCreatedAt = $subscribeSale->created_at;
                            }
                        }

                        if (!empty($subscribeSaleCreatedAt) && !empty($subscribeSale)) {
                            // Use the same expiration logic as getActiveSubscribes()
                            // Honor custom_expiration_date if set (could be from renewal extension)
                            $createdAt = Carbon::createFromTimestamp($subscribeSaleCreatedAt);
                            $now = Carbon::now();
                            $usedDays = $createdAt->diffInDays($now);
                            $calculatedExpiration = $subscribeSaleCreatedAt + ($subscribe->days * 86400);
                            $maxReasonableExpiration = $subscribeSaleCreatedAt + (($subscribe->days * 3) + 7) * 86400;
                            
                            $isExpired = false;
                            if (!empty($subscribeSale->custom_expiration_date)) {
                                if ($subscribeSale->custom_expiration_date > $maxReasonableExpiration) {
                                    $effectiveExpiration = $calculatedExpiration;
                                } else {
                                    $effectiveExpiration = $subscribeSale->custom_expiration_date;
                                }
                                $isExpired = $effectiveExpiration <= time();
                            } else {
                                $isExpired = $subscribe->days > 0 && $usedDays >= $subscribe->days;
                            }
                            
                            if ($isExpired) {
                                $hasBought = false;
                            }
                        } elseif (!empty($subscribeSaleCreatedAt)) {
                            // Fallback for installment orders (no custom_expiration_date)
                            $createdAt = Carbon::createFromTimestamp($subscribeSaleCreatedAt);
                            $now = Carbon::now();
                            $usedDays = $createdAt->diffInDays($now);
                            if ($subscribe->days > 0 && $usedDays >= $subscribe->days) {
                                $hasBought = false;
                            }
                        }
                    } else {
                        $hasBought = false;
                    }
                }

                if ($hasBought and !empty($this->access_days) and $checkExpired) {
                    $hasBought = $this->checkHasExpiredAccessDays($sale->created_at, $sale->gift_id);
                }
            }

            if (!$hasBought) {
                $hasBought = ($this->creator_id == $user->id or $this->teacher_id == $user->id);

                if (!$hasBought) {
                    $partnerTeachers = !empty($this->webinarPartnerTeacher) ? $this->webinarPartnerTeacher->pluck('teacher_id')->toArray() : [];

                    $hasBought = in_array($user->id, $partnerTeachers);
                }
            }

            if (!$hasBought) {
                $hasBought = $user->isAdmin();
            }

            // Fallback: Check for active SubscribeUse records directly
            // This ensures subscription-based purchases are detected even if sale lookup fails
            // Allow access if there's an active subscription, even if there's a refunded sale
            // (User may have refunded and then resubscribed)
            // IMPORTANT: Also verify there's a corresponding Sale record for this webinar
            if (!$hasBought) {
                // Get ALL active SubscribeUse records for this webinar
                $activeUses = \App\Models\SubscribeUse::where('user_id', $user->id)
                    ->where('webinar_id', $this->id)
                    ->where('active', true)
                    ->where(function($query) {
                        $query->whereNull('expired_at')->orWhere('expired_at', '>', time());
                    })
                    ->get();

                // Check each active use to find one with a non-expired subscription
                // Note: Subscription purchases create SubscribeUse records directly,
                // so we don't require a Sale record for the specific webinar
                foreach ($activeUses as $activeUse) {
                    // Verify the subscription is still valid
                    $subscribe = \App\Models\Subscribe::find($activeUse->subscribe_id);
                    if ($subscribe) {
                        // Find the subscription purchase sale to verify subscription is still valid
                        $subscribeSale = \App\Models\Sale::where('buyer_id', $user->id)
                            ->where('type', \App\Models\Sale::$subscribe)
                            ->where('subscribe_id', $subscribe->id)
                            ->whereNull('refund_at')
                            ->latest('created_at')
                            ->first();

                        if ($subscribeSale) {
                            // Use the same expiration logic as getActiveSubscribes()
                            // Honor custom_expiration_date if set (could be from renewal extension)
                            $createdAt = \Carbon\Carbon::createFromTimestamp($subscribeSale->created_at);
                            $now = \Carbon\Carbon::now();
                            $usedDays = $createdAt->diffInDays($now);
                            $calculatedExpiration = $subscribeSale->created_at + ($subscribe->days * 86400);
                            $maxReasonableExpiration = $subscribeSale->created_at + (($subscribe->days * 3) + 7) * 86400;
                            
                            $isExpired = false;
                            if (!empty($subscribeSale->custom_expiration_date)) {
                                if ($subscribeSale->custom_expiration_date > $maxReasonableExpiration) {
                                    $effectiveExpiration = $calculatedExpiration;
                                } else {
                                    $effectiveExpiration = $subscribeSale->custom_expiration_date;
                                }
                                $isExpired = $effectiveExpiration <= time();
                            } else {
                                $isExpired = $subscribe->days > 0 && $usedDays >= $subscribe->days;
                            }
                            
                            // If subscription is not expired (or infinite), grant access
                            if (!$isExpired) {
                                $hasBought = true;
                                break; // Found a valid subscription, no need to check others
                            }
                        }
                    }
                }
            }

            // Only check bundle/installment/gift if there's no refunded sale for this course
            // If the direct sale was refunded, don't grant access through alternative methods
            if (!$hasBought && !$hasRefundedSale) {
                $bundleWebinar = BundleWebinar::where('webinar_id', $this->id)
                    ->with([
                        'bundle'
                    ])->get();

                if ($bundleWebinar->isNotEmpty()) {
                    foreach ($bundleWebinar as $item) {
                        if (!empty($item->bundle) and $item->bundle->checkUserHasBought($user)) {
                            $hasBought = true;
                            break;
                        }
                    }
                }
            }

            /* Check Installment */
            if (!$hasBought && !$hasRefundedSale) {
                $installmentOrder = $this->getInstallmentOrder();

                if (!empty($installmentOrder)) {
                    $hasBought = true;

                    if ($installmentOrder->checkOrderHasOverdue()) {
                        $overdueIntervalDays = getInstallmentsSettings('overdue_interval_days');

                        if (empty($overdueIntervalDays) or $installmentOrder->overdueDaysPast() > $overdueIntervalDays) {
                            $hasBought = false;
                        }
                    }
                }
            }

            /* Check Gift */
            if (!$hasBought && !$hasRefundedSale) {
                $gift = Gift::query()->where('email', $user->email)
                    ->where('status', 'active')
                    ->where('webinar_id', $this->id)
                    ->where(function ($query) {
                        $query->whereNull('date');
                        $query->orWhere('date', '<', time());
                    })
                    ->whereHas('sale', function ($query) {
                        $query->whereNull('refund_at')
                            ->where('access_to_purchased_item', true);
                    })
                    ->first();

                if (!empty($gift)) {
                    $hasBought = true;
                }
            }
        }

        return $hasBought;
    }

    public function getInstallmentOrder()
    {
        $user = auth()->user();

        if (!empty($user)) {
            return InstallmentOrder::query()->where('user_id', $user->id)
                ->where('webinar_id', $this->id)
                ->where('status', 'open')
                ->whereNull('refund_at')
                ->first();
        }

        return null;
    }

    public function getFilesLearningProgressStat($userId = null)
    {
        $passed = 0;

        if (empty($userId)) {
            $userId = auth()->id();
        }

        $files = $this->files()
            ->where('status', 'active')
            ->get();

        foreach ($files as $file) {
            $status = CourseLearning::where('user_id', $userId)
                ->where('file_id', $file->id)
                ->first();

            if (!empty($status)) {
                $passed += 1;
            }
        }

        return [
            'passed' => $passed,
            'count' => count($files)
        ];
    }

    public function getSessionsLearningProgressStat($userId = null)
    {
        $passed = 0;

        if (empty($userId)) {
            $userId = auth()->id();
        }

        $sessions = $this->sessions()
            ->where('status', 'active')
            ->get();

        foreach ($sessions as $session) {
            $status = CourseLearning::where('user_id', $userId)
                ->where('session_id', $session->id)
                ->first();

            if (!empty($status)) {
                $passed += 1;
            }
        }

        return [
            'passed' => $passed,
            'count' => count($sessions)
        ];
    }

    public function getTextLessonsLearningProgressStat($userId = null)
    {
        $passed = 0;

        if (empty($userId)) {
            $userId = auth()->id();
        }

        $textLessons = $this->textLessons()
            ->where('status', 'active')
            ->get();

        foreach ($textLessons as $textLesson) {
            $status = CourseLearning::where('user_id', $userId)
                ->where('text_lesson_id', $textLesson->id)
                ->first();

            if (!empty($status)) {
                $passed += 1;
            }
        }

        return [
            'passed' => $passed,
            'count' => count($textLessons)
        ];
    }

    public function getAssignmentsLearningProgressStat($userId = null)
    {
        $passed = 0;

        if (empty($userId)) {
            $userId = auth()->id();
        }

        $assignments = $this->assignments()
            ->where('status', 'active')
            ->get();

        foreach ($assignments as $assignment) {
            $assignmentHistory = WebinarAssignmentHistory::where('assignment_id', $assignment->id)
                ->where('student_id', $userId)
                ->where('status', WebinarAssignmentHistory::$passed)
                ->first();

            if (!empty($assignmentHistory)) {
                $passed += 1;
            }
        }

        return [
            'passed' => $passed,
            'count' => count($assignments)
        ];
    }

    public function getQuizzesLearningProgressStat($userId = null)
    {
        $passed = 0;

        if (empty($userId)) {
            $userId = auth()->id();
        }

        $quizzes = $this->quizzes()
            ->where('status', 'active')
            ->get();

        foreach ($quizzes as $quiz) {
            $quizHistory = QuizzesResult::where('quiz_id', $quiz->id)
                ->where('user_id', $userId)
                ->where('status', QuizzesResult::$passed)
                ->first();

            if (!empty($quizHistory)) {
                $passed += 1;
            }
        }

        return [
            'passed' => $passed,
            'count' => count($quizzes)
        ];
    }

    public function getProgress($isLearningPage = false)
    {
        $progress = 0;

        if (
            auth()->check() and
            $this->checkUserHasBought() and
            (
                !$this->isWebinar() or
                ($this->isWebinar() and $this->isProgressing()) or
                $isLearningPage
            )
        ) {
            $user_id = auth()->id();

            $filesStat = $this->getFilesLearningProgressStat($user_id);
            $sessionsStat = $this->getSessionsLearningProgressStat($user_id);
            $textLessonsStat = $this->getTextLessonsLearningProgressStat($user_id);
            $assignmentsStat = $this->getAssignmentsLearningProgressStat($user_id);
            $quizzesStat = $this->getQuizzesLearningProgressStat($user_id);

            $passed = $filesStat['passed'] + $sessionsStat['passed'] + $textLessonsStat['passed'] + $assignmentsStat['passed'] + $quizzesStat['passed'];
            $count = $filesStat['count'] + $sessionsStat['count'] + $textLessonsStat['count'] + $assignmentsStat['count'] + $quizzesStat['count'];

            if ($passed > 0 and $count > 0) {
                $progress = ($passed * 100) / $count;

                $this->handleLearningProgress100Reward($progress, $user_id, $this->id);
            }
        } else if (!is_null($this->capacity)) {
            $salesCount = $this->getSalesCount();

            if ($salesCount > 0) {
                $progress = (!empty($this->capacity) and $this->capacity > 0) ? (($salesCount * 100) / $this->capacity) : 0;
            }
        }

        return round($progress, 2);
    }

    public function checkShowProgress($isLearningPage = false)
    {
        $show = false;

        if (
            auth()->check() and
            $this->checkUserHasBought() and
            (
                !$this->isWebinar() or
                ($this->isWebinar() and $this->isProgressing()) or
                $isLearningPage
            )
        ) {
            $show = true;
        } else if (!is_null($this->capacity)) {
            $show = true;
        }

        return $show;
    }

    public function handleLearningProgress100Reward($progress, $userId, $itemId)
    {
        if ($progress >= 100) {
            $rewardScore = RewardAccounting::calculateScore(Reward::LEARNING_PROGRESS_100);
            RewardAccounting::makeRewardAccounting($userId, $rewardScore, Reward::LEARNING_PROGRESS_100, $itemId, true);
        }
    }

    public function getImageCover()
    {
        return $this->image_cover;
    }

    public function getImage()
    {
        return $this->thumbnail;
    }

    public function getUrl()
    {
        return url('/course/' . $this->slug);
    }

    public function getLearningPageUrl()
    {
        return url('/course/learning/' . $this->slug);
    }

    public function getNoticeboardsPageUrl()
    {
        return $this->getLearningPageUrl() . '/noticeboards';
    }

    public function getForumPageUrl()
    {
        return $this->getLearningPageUrl() . '/forum';
    }

    public function isCourse()
    {
        return ($this->type == 'course');
    }

    public function isTextCourse()
    {
        return ($this->type == 'text_lesson');
    }

    public function isWebinar()
    {
        return ($this->type == 'webinar');
    }

    public function canAccess($user = null)
    {
        $result = false;

        if (!$user) {
            $user = auth()->user();
        }

        if (!empty($user)) {
            if ($this->creator_id == $user->id or $this->teacher_id == $user->id) {
                $result = true;
            }

            // Allow Access To Partner Teachers
            if (!$result and $this->isPartnerTeacher($user->id)) {
                $result = true;
            }
        }

        return $result;
    }

    public function checkCapacityReached()
    {
        $result = false;

        if (!is_null($this->capacity)) {
            $salesCount = !empty($this->sales_count) ? $this->sales_count : $this->sales()->count();

            $result = $salesCount >= $this->capacity;
        }

        return $result;
    }

  public function canSale()
{
    $result = true;

    // Bypass capacity check for live classes (webinars) or finished live sessions
    if (!is_null($this->capacity) && $this->type != 'webinar') {
        $salesCount = !empty($this->sales_count) ? $this->sales_count : $this->sales()->count();
        $result = $salesCount < $this->capacity;
    }

    // Allow live classes to always be sold regardless of the time
    if ($result && $this->type == 'webinar') {
        $result = true;  // Skip the date check entirely for live webinars
    }

    return $result;
}

    public function canJoinToWaitlist()
    {
        $hasBought = $this->checkUserHasBought();

        return ($this->enable_waitlist and !$hasBought and !$this->canSale());
    }

    public function cantSaleStatus($hasBought)
    {
        $status = '';

        if ($hasBought) {
            $status = 'js-course-has-bought-status';
        } else {

            if (!is_null($this->capacity)) {
                $salesCount = !empty($this->sales_count) ? $this->sales_count : $this->sales()->count();

                if ($salesCount >= $this->capacity) {
                    $status = 'js-course-not-capacity-status';
                }
            } elseif ($this->type == 'webinar' and $this->start_date <= time()) {
                $status = 'js-course-has-started-status';
            }
        }

        return $status;
    }

    public function addToCalendarLink()
    {

        $date = \DateTime::createFromFormat('j M Y H:i', dateTimeFormat($this->start_date, 'j M Y H:i', false));

        $link = Link::create($this->title, $date, $date); //->description('Cookies & cocktails!')

        return $link->google();
    }

    public function activeSpecialOffer()
    {
        $activeSpecialOffer = SpecialOffer::where('webinar_id', $this->id)
            ->where('status', SpecialOffer::$active)
            ->where('from_date', '<', time())
            ->where('to_date', '>', time())
            ->first();

        return $activeSpecialOffer ?? false;
    }

    public function nextSession()
    {
        $sessions = $this->sessions()
            ->orderBy('date', 'asc')
            ->get();
        $time = time();

        foreach ($sessions as $session) {
            if ($session->date > $time) {
                return $session;
            }
        }

        return null;
    }

    public function lastSession()
    {
        $session = $this->sessions()
            ->orderBy('date', 'desc')
            ->first();

        return $session;
    }

    public function isProgressing()
    {
        $lastSession = $this->lastSession();
        //$nextSession = $this->nextSession();
        $isProgressing = false;

        if (!empty($lastSession)) {
            $agoraHistory = AgoraHistory::where('session_id', $lastSession->id)
                ->first();

            if (
                ($lastSession->session_api != "agora" and $lastSession->date > time()) or
                ($lastSession->session_api == "agora" and ($lastSession->date > time() and (empty($agoraHistory) or empty($agoraHistory->end_at))))
            ) {
                $isProgressing = true;
            }
        }

        if ($this->start_date > time()) {
            $isProgressing = true;
        }

        return $isProgressing;
    }

    public function getShareLink($social)
    {
        $link = ShareFacade::page($this->getUrl(), $this->title)
            ->facebook()
            ->twitter()
            ->whatsapp()
            ->telegram()
            ->getRawLinks();

        return !empty($link[$social]) ? $link[$social] : '';
    }

    public function isDownloadable()
    {
        $downloadable = $this->downloadable;

        if ($this->files->count() > 0) {
            $downloadableFiles = $this->files->where('downloadable', true)->count();

            if ($downloadableFiles > 0) {
                $downloadable = true;
            }
        }

        return $downloadable;
    }

    public function isOwner($userId = null)
    {
        if (empty($userId)) {
            $userId = auth()->id();
        }

        return (($this->creator_id == $userId) or ($this->teacher_id == $userId));
    }

    public function isPartnerTeacher($userId = null)
    {
        if (empty($userId)) {
            $userId = auth()->id();
        }

        $partnerTeachers = !empty($this->webinarPartnerTeacher) ? $this->webinarPartnerTeacher->pluck('teacher_id')->toArray() : [];

        return in_array($userId, $partnerTeachers);
    }

    public function getPrice()
    {
        $price = $this->price;

        $specialOffer = $this->activeSpecialOffer();
        if (!empty($specialOffer)) {
            $price = $price - ($price * $specialOffer->percent / 100);
        }

        return $price;
    }

    public function getStudentsIds()
    {
        $studentsIds = Sale::query()->where('webinar_id', $this->id)
            ->whereNull('refund_at')
            ->whereHas('buyer')
            ->pluck('buyer_id')
            ->toArray();

        // get users by installments
        $installmentOrders = InstallmentOrder::query()
            ->where('webinar_id', $this->id)
            ->where('status', 'open')
            ->whereNull('refund_at')
            ->get();

        foreach ($installmentOrders as $installmentOrder) {
            if (!empty($installmentOrder)) {
                $hasBought = true;

                if ($installmentOrder->checkOrderHasOverdue()) {
                    $overdueIntervalDays = getInstallmentsSettings('overdue_interval_days');

                    if (empty($overdueIntervalDays) or $installmentOrder->overdueDaysPast() > $overdueIntervalDays) {
                        $hasBought = false;
                    }
                }

                if ($hasBought) {
                    $studentsIds[] = $installmentOrder->user_id;
                }
            }
        }

        // get users by gifts
        $gifts = Gift::query()
            ->where('status', 'active')
            ->where('webinar_id', $this->id)
            ->where(function ($query) {
                $query->whereNull('date');
                $query->orWhere('date', '<', time());
            })
            ->whereHas('sale')
            ->get();

        foreach ($gifts as $gift) {
            $user = User::query()->select('id', 'email')->where('email', $gift->email)->first();

            if (!empty($user)) {
                $studentsIds[] = $user->id;
            }
        }

        // get users by bundle
        $bundleWebinar = BundleWebinar::where('webinar_id', $this->id)
            ->with([
                'bundle'
            ])->get();

        if ($bundleWebinar->isNotEmpty()) {
            foreach ($bundleWebinar as $item) {
                if (!empty($item->bundle)) {
                    $bundleStudents = $item->bundle->getStudentsIds();

                    $studentsIds = array_merge($studentsIds, $bundleStudents);
                }
            }
        }

        return array_unique($studentsIds);
    }

    public function sendNotificationToAllStudentsForNewQuizPublished($quiz)
    {
        $studentsIds = $this->getStudentsIds();

        $notifyOptions = [
            '[q.title]' => $quiz->title,
            '[c.title]' => $this->title
        ];

        if (count($studentsIds)) {
            foreach ($studentsIds as $studentId) {
                sendNotification("new_quiz", $notifyOptions, $studentId);
            }
        }

        $gifts = Gift::query()
            ->where('status', 'active')
            ->where('webinar_id', $this->id)
            ->where(function ($query) {
                $query->whereNull('date');
                $query->orWhere('date', '<', time());
            })
            ->whereHas('sale')
            ->get();

        foreach ($gifts as $gift) {
            $user = User::query()->select('id', 'email')->where('email', $gift->email)->first();

            if (empty($user)) {
                sendNotificationToEmail("new_quiz", $notifyOptions, $gift->email);
            }
        }
    }

    public function makeCertificateForUser($user)
    {
        if (!empty($user) and $this->certificate and $this->getProgress(true) >= 100) {
            $check = Certificate::where('type', 'course')
                ->where('student_id', $user->id)
                ->where('webinar_id', $this->id)
                ->first();

            if (empty($check)) {
                $makeCertificate = new MakeCertificate();
                $userCertificate = $makeCertificate->saveCourseCertificate($user, $this);

                $certificateReward = RewardAccounting::calculateScore(Reward::CERTIFICATE);
                RewardAccounting::makeRewardAccounting($userCertificate->student_id, $certificateReward, Reward::CERTIFICATE, $userCertificate->id, true);
            }
        }
    }

    public function getSalesCount()
    {
        $count = $this->sales()->count();

        if (!is_null($this->sales_count_number)) { // Add fake sales numbers
            $count += $this->sales_count_number;
        }

        return $count;
    }

}
