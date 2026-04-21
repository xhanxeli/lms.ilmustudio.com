<?php

namespace App\Handlers;

use UniSharp\LaravelFilemanager\Handlers\ConfigHandler;

class LfmConfigHandler extends ConfigHandler
{
    public function userField()
    {
        $user = auth()->user();
        
        if (!$user) {
            return 'guest';
        }
        
        // For admin users and LMS Manager roles, return a shared folder name so they use the same storage
        $isAdmin = $user->isAdmin();
        $isLmsManager = !empty($user->role_name) && 
                       (stripos($user->role_name, 'manager') !== false || 
                        $user->role_name === 'LMS_Manager' ||
                        $user->role_name === 'lms_manager' ||
                        $user->role_name === 'LMS Manager');
        
        if ($isAdmin || $isLmsManager) {
            return 'admin'; // Shared folder for all admin users and LMS Managers
        }
        
        // For regular users, use their user ID (separate folders)
        return $user->id;
    }
}

