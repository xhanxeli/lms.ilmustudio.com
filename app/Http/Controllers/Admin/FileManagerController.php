<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileManagerController extends Controller
{
    public function index(Request $request)
    {
        // Allow any admin user to access file manager
        // No specific permission check needed as it's a general utility

        $searchQuery = $request->get('search', '');
        $searchResults = [];

        if (!empty($searchQuery)) {
            $searchResults = $this->searchFiles($searchQuery);
        }

        $data = [
            'pageTitle' => trans('admin/main.file_manager'),
            'searchQuery' => $searchQuery,
            'searchResults' => $searchResults,
        ];

        return view('admin.filemanager.index', $data);
    }

    private function searchFiles($query)
    {
        $basePath = public_path('store');
        $results = [];
        $maxResults = 500; // Limit results to prevent performance issues
        
        // Ensure base path exists and is readable
        if (!is_dir($basePath) || !is_readable($basePath)) {
            \Log::error("FileManager: Base path is not accessible: " . $basePath);
            return [];
        }
        
        // Trim and validate query
        $query = trim($query);
        if (empty($query)) {
            return [];
        }
        
        // Recursively search for files matching the query
        $this->searchDirectory($basePath, $query, $results, $basePath, $maxResults);
        
        return $results;
    }

    private function searchDirectory($directory, $query, &$results, $basePath, $maxResults = 500)
    {
        // Stop searching if we've reached the max results limit
        if (count($results) >= $maxResults) {
            return;
        }

        if (!is_dir($directory) || !is_readable($directory)) {
            return;
        }

        try {
            $items = scandir($directory);
        } catch (\Exception $e) {
            return;
        }
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            // Stop if we've reached max results
            if (count($results) >= $maxResults) {
                return;
            }

            $fullPath = $directory . DIRECTORY_SEPARATOR . $item;

            try {
                if (is_dir($fullPath)) {
                    // Recursively search subdirectories
                    $this->searchDirectory($fullPath, $query, $results, $basePath, $maxResults);
                } else {
                    // Check if filename contains the search query (case-insensitive)
                    if (stripos($item, $query) !== false) {
                        $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $fullPath);
                        $relativePath = str_replace('\\', '/', $relativePath); // Normalize path separators
                        
                        // Double-check file exists and is readable
                        if (file_exists($fullPath) && is_readable($fullPath)) {
                            try {
                                $results[] = [
                                    'name' => $item,
                                    'path' => $relativePath,
                                    'url' => '/store/' . $relativePath,
                                    'size' => @filesize($fullPath) ?: 0,
                                    'modified' => @filemtime($fullPath) ?: time(),
                                ];
                            } catch (\Exception $e) {
                                // Skip this file if we can't get its info
                                continue;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Skip files/directories that can't be accessed
                continue;
            }
        }
    }
}

