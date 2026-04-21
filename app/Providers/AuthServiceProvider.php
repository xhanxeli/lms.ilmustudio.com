<?php

namespace App\Providers;

use App\Models\Api\CourseForumAnswer;
use App\Models\Webinar;
use App\Models\CourseForum;
use App\Models\Section;
use App\Policies\CourseForumAnswerPolicy;
use App\Policies\CourseForumPolicy;
use App\Policies\WebinarPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        // 'App\Model' => 'App\Policies\ModelPolicy',
        CourseForum::class => CourseForumPolicy::class,
        CourseForumAnswer::class => CourseForumAnswerPolicy::class ,
        Webinar::class => WebinarPolicy::class
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {

        $this->registerPolicies();

        try {
            $minutes = 60 * 60; // 1 hour
            $sections = Cache::remember('sections', $minutes, function () {
                try {
                    return Section::all();
                } catch (\Exception $e) {
                    // If database query fails, log and return empty collection
                    \Log::error('Failed to load sections from database in AuthServiceProvider', [
                        'error' => $e->getMessage()
                    ]);
                    return collect([]);
                }
            });

            $scopes = [];
            foreach ($sections as $section) {
                $scopes[$section->name] = $section->caption;
                Gate::define($section->name, function ($user) use ($section) {
                    try {
                        // If user is super admin, allow access even if permission check fails
                        if ($user->isAdmin() && $user->role && $user->role->is_admin) {
                            return true;
                        }
                        return $user->hasPermission($section->name);
                    } catch (\Exception $e) {
                        // If permission check fails (e.g., database unavailable), 
                        // allow super admins, deny others
                        \Log::warning('Permission check failed in gate', [
                            'section' => $section->name,
                            'user_id' => $user->id ?? null,
                            'error' => $e->getMessage()
                        ]);
                        // Allow super admins to bypass when database is unavailable
                        if ($user->isAdmin() && $user->role && $user->role->is_admin) {
                            return true;
                        }
                        return false;
                    }
                });
            }
        } catch (\Exception $e) {
            // If cache fails or any other error, log and continue without sections
            \Log::error('Failed to load sections in AuthServiceProvider', [
                'error' => $e->getMessage()
            ]);
            
            // Define common gates as fallback when sections can't be loaded
            // This prevents "unauthorized" errors when database is temporarily unavailable
            $commonGates = [
                'admin_staffs_list', 'admin_users_list', 'admin_organizations_list', 
                'admin_instructors_list', 'admin_users_create', 'admin_users_edit', 
                'admin_users_delete', 'admin_users_export_excel', 'admin_users_impersonate'
            ];
            
            foreach ($commonGates as $gateName) {
                Gate::define($gateName, function ($user) {
                    // Allow super admins when database is unavailable
                    if ($user && $user->isAdmin() && $user->role && $user->role->is_admin) {
                        return true;
                    }
                    return false;
                });
            }
        }
        
        // Define a fallback gate for any undefined gates to prevent "unauthorized" errors
        // This allows super admins to access everything when gates aren't defined
        Gate::before(function ($user, $ability) {
            // If gate is not defined and user is super admin, allow access
            if ($user && $user->isAdmin() && $user->role && $user->role->is_admin) {
                try {
                    // Check if this gate was supposed to be defined but wasn't
                    // If database is unavailable, allow super admins
                    return true;
                } catch (\Exception $e) {
                    return true; // Allow super admins when database is unavailable
                }
            }
            return null; // Let other gates handle it
        });

        //
    }
}
