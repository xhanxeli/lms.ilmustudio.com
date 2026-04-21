<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=EDGE" />
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- Chrome, Firefox OS and Opera -->
  <meta name="theme-color" content="#333844">
  <!-- Windows Phone -->
  <meta name="msapplication-navbutton-color" content="#333844">
  <!-- iOS Safari -->
  <meta name="apple-mobile-web-app-status-bar-style" content="#333844">

  <title>{{ trans('laravel-filemanager::lfm.title-page') }}</title>
  <link rel="shortcut icon" type="image/png" href="{{ asset('vendor/laravel-filemanager/img/72px color.png') }}">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.1.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.5.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jquery-ui-dist@1.12.1/jquery-ui.min.css">
  <link rel="stylesheet" href="{{ asset('vendor/laravel-filemanager/css/cropper.min.css') }}">
  <link rel="stylesheet" href="{{ asset('vendor/laravel-filemanager/css/dropzone.min.css') }}">
  <link rel="stylesheet" href="{{ asset('vendor/laravel-filemanager/css/mime-icons.min.css') }}">
  <style>{!! \File::get(base_path('vendor/unisharp/laravel-filemanager/public/css/lfm.css')) !!}</style>
  {{-- Use the line below instead of the above if you need to cache the css. --}}
  {{-- <link rel="stylesheet" href="{{ asset('/vendor/laravel-filemanager/css/lfm.css') }}"> --}}
</head>
<body>
  <nav class="navbar sticky-top navbar-expand-lg navbar-dark" id="nav">
    <a class="navbar-brand invisible-lg d-none d-lg-inline" id="to-previous">
      <i class="fas fa-arrow-left fa-fw"></i>
      <span class="d-none d-lg-inline">{{ trans('laravel-filemanager::lfm.nav-back') }}</span>
    </a>
    <a class="navbar-brand d-block d-lg-none" id="show_tree">
      <i class="fas fa-bars fa-fw"></i>
    </a>
    <a class="navbar-brand d-block d-lg-none" id="current_folder"></a>
    <a id="loading" class="navbar-brand"><i class="fas fa-spinner fa-spin"></i></a>
    <div class="ml-auto px-2 d-flex align-items-center">
      <div class="input-group input-group-sm mr-2" style="max-width: 250px;">
        <input type="text" id="file-search-input" class="form-control" placeholder="{{ trans('laravel-filemanager::lfm.nav-search') }}" style="background: rgba(255,255,255,0.2); border-color: rgba(255,255,255,0.3); color: #fff;">
        <div class="input-group-append">
          <button class="btn btn-outline-light" type="button" id="file-search-btn">
            <i class="fas fa-search"></i>
          </button>
          <button class="btn btn-outline-light d-none" type="button" id="file-search-clear">
            <i class="fas fa-times"></i>
          </button>
        </div>
      </div>
      <a class="navbar-link d-none" id="multi_selection_toggle">
        <i class="fa fa-check-double fa-fw"></i>
        <span class="d-none d-lg-inline">{{ trans('laravel-filemanager::lfm.menu-multiple') }}</span>
      </a>
    </div>
    <a class="navbar-toggler collapsed border-0 px-1 py-2 m-0" data-toggle="collapse" data-target="#nav-buttons">
      <i class="fas fa-cog fa-fw"></i>
    </a>
    <div class="collapse navbar-collapse flex-grow-0" id="nav-buttons">
      <ul class="navbar-nav">
        <li class="nav-item">
          <a class="nav-link" data-display="grid">
            <i class="fas fa-th-large fa-fw"></i>
            <span>{{ trans('laravel-filemanager::lfm.nav-thumbnails') }}</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" data-display="list">
            <i class="fas fa-list-ul fa-fw"></i>
            <span>{{ trans('laravel-filemanager::lfm.nav-list') }}</span>
          </a>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">
            <i class="fas fa-sort fa-fw"></i>{{ trans('laravel-filemanager::lfm.nav-sort') }}
          </a>
          <div class="dropdown-menu dropdown-menu-right border-0"></div>
        </li>
      </ul>
    </div>
  </nav>

  <nav class="bg-light fixed-bottom border-top d-none" id="actions">
    <a data-action="open" data-multiple="false"><i class="fas fa-folder-open"></i>{{ trans('laravel-filemanager::lfm.btn-open') }}</a>
    <a data-action="preview" data-multiple="true"><i class="fas fa-images"></i>{{ trans('laravel-filemanager::lfm.menu-view') }}</a>
    <a data-action="use" data-multiple="true"><i class="fas fa-check"></i>{{ trans('laravel-filemanager::lfm.btn-confirm') }}</a>
  </nav>

  <div class="d-flex flex-row">
    <div id="tree"></div>

    <div id="main">
      <div id="alerts"></div>

      <nav aria-label="breadcrumb" class="d-none d-lg-block" id="breadcrumbs">
        <ol class="breadcrumb">
          <li class="breadcrumb-item invisible">Home</li>
        </ol>
      </nav>

      <div id="empty" class="d-none">
        <i class="far fa-folder-open"></i>
        {{ trans('laravel-filemanager::lfm.message-empty') }}
      </div>

      <div id="content"></div>
      <div id="pagination"></div>

      <a id="item-template" class="d-none">
        <div class="square"></div>

        <div class="info">
          <div class="item_name text-truncate"></div>
          <time class="text-muted font-weight-light text-truncate"></time>
        </div>
      </a>
    </div>

    <div id="fab"></div>
  </div>

  <div class="modal fade" id="uploadModal" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h4 class="modal-title" id="myModalLabel">{{ trans('laravel-filemanager::lfm.title-upload') }}</h4>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aia-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <form action="{{ route('unisharp.lfm.upload') }}" role='form' id='uploadForm' name='uploadForm' method='post' enctype='multipart/form-data' class="dropzone">
            <div class="form-group" id="attachment">
              <div class="controls text-center">
                <div class="input-group w-100">
                  <a class="btn btn-primary w-100 text-white" id="upload-button">{{ trans('laravel-filemanager::lfm.message-choose') }}</a>
                </div>
              </div>
            </div>
            <input type='hidden' name='working_dir' id='working_dir'>
            <input type='hidden' name='type' id='type' value='{{ request("type") }}'>
            <input type='hidden' name='_token' value='{{csrf_token()}}'>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary w-100" data-dismiss="modal">{{ trans('laravel-filemanager::lfm.btn-close') }}</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="notify" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-body"></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary w-100" data-dismiss="modal">{{ trans('laravel-filemanager::lfm.btn-close') }}</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="confirm" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-body"></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary w-100" data-dismiss="modal">{{ trans('laravel-filemanager::lfm.btn-close') }}</button>
          <button type="button" class="btn btn-primary w-100" data-dismiss="modal">{{ trans('laravel-filemanager::lfm.btn-confirm') }}</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="dialog" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h4 class="modal-title"></h4>
        </div>
        <div class="modal-body">
          <input type="text" class="form-control">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary w-100" data-dismiss="modal">{{ trans('laravel-filemanager::lfm.btn-close') }}</button>
          <button type="button" class="btn btn-primary w-100" data-dismiss="modal">{{ trans('laravel-filemanager::lfm.btn-confirm') }}</button>
        </div>
      </div>
    </div>
  </div>

  <div id="carouselTemplate" class="d-none carousel slide bg-light" data-ride="carousel">
    <ol class="carousel-indicators">
      <li data-target="#previewCarousel" data-slide-to="0" class="active"></li>
    </ol>
    <div class="carousel-inner">
      <div class="carousel-item active">
        <a class="carousel-label"></a>
        <div class="carousel-image"></div>
      </div>
    </div>
    <a class="carousel-control-prev" href="#previewCarousel" role="button" data-slide="prev">
      <div class="carousel-control-background" aria-hidden="true">
        <i class="fas fa-chevron-left"></i>
      </div>
      <span class="sr-only">Previous</span>
    </a>
    <a class="carousel-control-next" href="#previewCarousel" role="button" data-slide="next">
      <div class="carousel-control-background" aria-hidden="true">
        <i class="fas fa-chevron-right"></i>
      </div>
      <span class="sr-only">Next</span>
    </a>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/jquery@3.2.1/dist/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/popper.js@1.12.3/dist/umd/popper.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.1.0/dist/js/bootstrap.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/jquery-ui-dist@1.12.1/jquery-ui.min.js"></script>
  <script src="{{ asset('vendor/laravel-filemanager/js/cropper.min.js') }}"></script>
  <script src="{{ asset('vendor/laravel-filemanager/js/dropzone.min.js') }}"></script>
  <script>
    var lang = {!! json_encode(trans('laravel-filemanager::lfm')) !!};
    var actions = [
      // {
      //   name: 'use',
      //   icon: 'check',
      //   label: 'Confirm',
      //   multiple: true
      // },
      {
        name: 'rename',
        icon: 'edit',
        label: lang['menu-rename'],
        multiple: false
      },
      {
        name: 'download',
        icon: 'download',
        label: lang['menu-download'],
        multiple: true
      },
      // {
      //   name: 'preview',
      //   icon: 'image',
      //   label: lang['menu-view'],
      //   multiple: true
      // },
      {
        name: 'move',
        icon: 'paste',
        label: lang['menu-move'],
        multiple: true
      },
      {
        name: 'resize',
        icon: 'arrows-alt',
        label: lang['menu-resize'],
        multiple: false
      },
      {
        name: 'crop',
        icon: 'crop',
        label: lang['menu-crop'],
        multiple: false
      },
      {
        name: 'trash',
        icon: 'trash',
        label: lang['menu-delete'],
        multiple: true
      },
    ];

    var sortings = [
      {
        by: 'alphabetic',
        icon: 'sort-alpha-down',
        label: lang['nav-sort-alphabetic']
      },
      {
        by: 'time',
        icon: 'sort-numeric-down',
        label: lang['nav-sort-time']
      }
    ];
  </script>
  <script>{!! \File::get(base_path('vendor/unisharp/laravel-filemanager/public/js/script.js')) !!}</script>
  {{-- Use the line below instead of the above if you need to cache the script. --}}
  {{-- <script src="{{ asset('vendor/laravel-filemanager/js/script.js') }}"></script> --}}
  
  <script>
    // File Search Functionality - Client-side filtering
    (function() {
      var searchInput = document.getElementById('file-search-input');
      var searchClear = document.getElementById('file-search-clear');
      var isSearchActive = false;
      var allItemsCache = []; // Store all items for search
      
      // Override loadItems to capture items from the response
      var originalLoadItems = window.loadItems;
      if (typeof originalLoadItems === 'function') {
        window.loadItems = function(page) {
          var result = originalLoadItems.call(this, page);
          result.done(function(data) {
            try {
              var response = JSON.parse(data);
              if (response && response.items && Array.isArray(response.items)) {
                // Store items for searching - merge with existing cache if paginated
                if (page && page > 1 && allItemsCache.length > 0) {
                  // Append items for pagination
                  allItemsCache = allItemsCache.concat(response.items);
                } else {
                  // Replace cache for first page or when resetting
                  allItemsCache = response.items.slice();
                }
              }
              // Also check global items variable after a short delay
              setTimeout(function() {
                if (typeof items !== 'undefined' && Array.isArray(items) && items.length > 0) {
                  // Merge with cache if different - use more comprehensive comparison
                  var newItems = items.filter(function(item) {
                    return !allItemsCache.some(function(cached) {
                      // Compare by name and url - be more lenient with whitespace
                      var cachedName = String(cached.name || '').trim().toLowerCase();
                      var itemName = String(item.name || '').trim().toLowerCase();
                      var cachedUrl = String(cached.url || '').trim();
                      var itemUrl = String(item.url || '').trim();
                      return cachedName === itemName && cachedUrl === itemUrl;
                    });
                  });
                  if (newItems.length > 0) {
                    allItemsCache = allItemsCache.concat(newItems);
                  } else {
                    // Update cache if items array has more items or different items
                    if (items.length > allItemsCache.length) {
                      allItemsCache = items.slice();
                    }
                  }
                }
                
                // Also try to extract from DOM if cache is still empty or small
                if (allItemsCache.length === 0 || (typeof items === 'undefined' || !Array.isArray(items) || items.length === 0)) {
                  var $items = $('#content .item');
                  if ($items.length > 0) {
                    var domItems = $items.map(function() {
                      var $item = $(this);
                      var $nameEl = $item.find('.item_name');
                      var name = '';
                      if ($nameEl.length > 0) {
                        name = ($nameEl.text() || $nameEl.html() || '').trim();
                      } else {
                        name = ($item.attr('title') || $item.data('name') || '').trim();
                      }
                      
                      if (!name) return null;
                      
                      // Check if already in cache
                      var exists = allItemsCache.some(function(cached) {
                        return String(cached.name || '').trim().toLowerCase() === name.toLowerCase();
                      });
                      
                      if (!exists) {
                        var iconClass = $item.find('.mime-icon').attr('class') || '';
                        var icon = 'file';
                        if (iconClass) {
                          var iconMatch = iconClass.match(/ico-([^\s]+)/);
                          if (iconMatch) {
                            icon = iconMatch[1];
                          }
                        }
                        
                        return {
                          name: name,
                          url: $item.data('url') || $item.attr('href') || '',
                          is_file: !$item.hasClass('folder'),
                          icon: icon,
                          time: $item.find('time').data('time') || Math.floor(Date.now() / 1000),
                          thumb_url: $item.find('.square').css('background-image') ? $item.find('.square').css('background-image').replace(/url\(['"]?(.+?)['"]?\)/, '$1') : null
                        };
                      }
                      return null;
                    }).get().filter(function(item) { return item !== null; });
                    
                    if (domItems.length > 0) {
                      allItemsCache = allItemsCache.concat(domItems);
                    }
                  }
                }
                
                // If search is active, re-apply filter
                if (isSearchActive && searchInput && searchInput.value.trim()) {
                  filterItems();
                }
              }, 200);
            } catch(e) {
              console.error('Error parsing search items:', e);
            }
          });
          return result;
        };
      }
      
      function filterItems(searchTerm) {
        if (!searchInput) return;
        
        searchTerm = (searchTerm || searchInput.value || '').trim();
        
        if (!searchTerm) {
          isSearchActive = false;
          if (searchClear) searchClear.classList.add('d-none');
          // Reload current page to restore original view
          if (typeof originalLoadItems === 'function') {
            originalLoadItems(1);
          }
          return;
        }
        
        isSearchActive = true;
        if (searchClear) searchClear.classList.remove('d-none');
        // Ensure search term is treated as a string, not a number
        // Preserve leading zeros by explicitly converting to string first
        var searchLower = String(String(searchTerm)).toLowerCase();
        
        // Try to get items from multiple sources - prioritize cache
        var itemsToSearch = [];
        
        // First, try the cache (most comprehensive)
        if (allItemsCache.length > 0) {
          itemsToSearch = allItemsCache;
        } 
        // Second, try global items array
        else if (typeof items !== 'undefined' && Array.isArray(items) && items.length > 0) {
          itemsToSearch = items;
        } 
        // Last resort: extract from DOM
        else {
          var $items = $('#content .item');
          if ($items.length > 0) {
            itemsToSearch = $items.map(function() {
              var $item = $(this);
              var dataId = $item.data('id');
              var originalItem = null;
              
              // Try to get original item data from global items array if available
              if (typeof items !== 'undefined' && Array.isArray(items) && dataId !== undefined) {
                originalItem = items[dataId];
              }
              
              // Use original item data if available, otherwise extract from DOM
              if (originalItem) {
                return originalItem;
              } else {
                // Extract icon class properly
                var iconClass = $item.find('.mime-icon').attr('class') || '';
                var icon = 'file';
                if (iconClass) {
                  var iconMatch = iconClass.match(/ico-([^\s]+)/);
                  if (iconMatch) {
                    icon = iconMatch[1];
                  }
                }
                
                // Extract name more robustly - check multiple selectors
                var name = '';
                var $nameEl = $item.find('.item_name');
                if ($nameEl.length > 0) {
                  name = $nameEl.text() || $nameEl.html() || '';
                } else {
                  // Fallback: try to get from title or data attributes
                  name = $item.attr('title') || $item.data('name') || '';
                }
                name = name.trim();
                
                return {
                  name: name,
                  url: $item.data('url') || $item.attr('href') || '',
                  is_file: !$item.hasClass('folder'),
                  icon: icon,
                  time: $item.find('time').data('time') || Math.floor(Date.now() / 1000),
                  thumb_url: $item.find('.square').css('background-image') ? $item.find('.square').css('background-image').replace(/url\(['"]?(.+?)['"]?\)/, '$1') : null
                };
              }
            }).get();
          }
        }
        
        // Also try to merge with DOM items if we have cache but want to be thorough
        if (itemsToSearch.length > 0 && allItemsCache.length === 0) {
          var $items = $('#content .item');
          if ($items.length > 0) {
            $items.each(function() {
              var $item = $(this);
              var $nameEl = $item.find('.item_name');
              var domName = '';
              if ($nameEl.length > 0) {
                domName = ($nameEl.text() || $nameEl.html() || '').trim();
              }
              
              // Check if this item is already in itemsToSearch
              var exists = itemsToSearch.some(function(item) {
                return item.name === domName || item.url === ($item.data('url') || $item.attr('href') || '');
              });
              
              if (!exists && domName) {
                var iconClass = $item.find('.mime-icon').attr('class') || '';
                var icon = 'file';
                if (iconClass) {
                  var iconMatch = iconClass.match(/ico-([^\s]+)/);
                  if (iconMatch) {
                    icon = iconMatch[1];
                  }
                }
                
                itemsToSearch.push({
                  name: domName,
                  url: $item.data('url') || $item.attr('href') || '',
                  is_file: !$item.hasClass('folder'),
                  icon: icon,
                  time: $item.find('time').data('time') || Math.floor(Date.now() / 1000),
                  thumb_url: $item.find('.square').css('background-image') ? $item.find('.square').css('background-image').replace(/url\(['"]?(.+?)['"]?\)/, '$1') : null
                });
              }
            });
          }
        }
        
        if (itemsToSearch.length === 0) {
          console.warn('No items available to search');
          return;
        }
        
        // Filter items - ensure proper string matching for dates like "07.01"
        var filteredItems = itemsToSearch.filter(function(item) {
          // Ensure name is treated as a string - preserve leading zeros
          var name = String(String(item.name || '')).trim();
          // Convert to lowercase for case-insensitive matching
          var nameLower = name.toLowerCase();
          
          // Use indexOf for reliable substring matching (handles special characters like dots correctly)
          // This will match "07.01" even if the filename is "File-07.01.pdf"
          var matchIndex = nameLower.indexOf(searchLower);
          if (matchIndex !== -1) {
            return true;
          }
          
          // Also try matching without dots as fallback (in case of encoding issues)
          var nameNoDots = nameLower.replace(/\./g, '');
          var searchNoDots = searchLower.replace(/\./g, '');
          if (nameNoDots.indexOf(searchNoDots) !== -1) {
            return true;
          }
          
          return false;
        });
        
        // Re-render filtered items
        renderFilteredItems(filteredItems);
      }
      
      function renderFilteredItems(filteredItems) {
        var content = $('#content');
        var empty = $('#empty');
        var pagination = $('#pagination');
        
        content.html('').removeAttr('class');
        pagination.html('').addClass('preserve_actions_space');
        
        // Update global items array with filtered items so selection works
        if (typeof items !== 'undefined') {
          items = filteredItems;
        }
        
        // Clear selected items when rendering new filtered results
        if (typeof selected !== 'undefined') {
          selected = [];
        }
        
        var hasItems = filteredItems.length !== 0;
        empty.toggleClass('d-none', hasItems);
        
        if (hasItems) {
          // Preserve display mode
          var displayMode = show_list === 1 ? 'list' : 'grid';
          content.addClass(displayMode);
          
          filteredItems.forEach(function(item, index) {
            var template = $('#item-template').clone()
              .removeAttr('id class')
              .attr('data-id', index)
              .click(function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof toggleSelected === 'function') {
                  toggleSelected.call(this, e);
                }
              })
              .dblclick(function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (item.is_file) {
                  if (typeof use === 'function' && typeof getSelectedItems === 'function') {
                    use(getSelectedItems());
                  }
                } else {
                  if (typeof goTo === 'function') {
                    goTo(item.url);
                  }
                }
              });
            
            var image;
            if (item.thumb_url) {
              image = $('<div>').css('background-image', 'url("' + item.thumb_url + '?timestamp=' + item.time + '")');
            } else {
              var icon = $('<div>').addClass('ico');
              image = $('<div>').addClass('mime-icon ico-' + (item.icon || 'file')).append(icon);
            }
            
            template.find('.square').append(image);
            template.find('.item_name').text(item.name);
            template.find('time').text((new Date(item.time * 1000)).toLocaleString());
            
            content.append(template);
          });
        }
        
        // Update selected style after rendering
        if (typeof updateSelectedStyle === 'function') {
          updateSelectedStyle();
        }
        
        if (typeof toggleActions === 'function') {
          toggleActions();
        }
      }
      
      // Search input handlers - wait for DOM and file manager to be ready
      $(document).ready(function() {
        setTimeout(function() {
          if (searchInput) {
            // Search on button click
            $('#file-search-btn').off('click').on('click', function(e) {
              e.preventDefault();
              filterItems();
            });
            
            // Search on Enter key
            $(searchInput).off('keypress').on('keypress', function(e) {
              if (e.which === 13) {
                e.preventDefault();
                filterItems();
              }
            });
            
            // Real-time search as user types (with debounce)
            var searchTimeout;
            $(searchInput).off('input').on('input', function() {
              clearTimeout(searchTimeout);
              var value = $(this).val().trim();
              
              if (value === '') {
                isSearchActive = false;
                if (searchClear) searchClear.classList.add('d-none');
                if (typeof originalLoadItems === 'function') {
                  originalLoadItems(1);
                }
              } else {
                searchTimeout = setTimeout(function() {
                  filterItems();
                }, 300);
              }
            });
          }
          
          // Clear search button
          if (searchClear) {
            $(searchClear).off('click').on('click', function(e) {
              e.preventDefault();
              if (searchInput) searchInput.value = '';
              isSearchActive = false;
              searchClear.classList.add('d-none');
              if (typeof originalLoadItems === 'function') {
                originalLoadItems(1);
              }
            });
          }
        }, 500); // Wait for file manager to initialize
      });
    })();
  </script>
  
  <style>
    #file-search-input::placeholder {
      color: rgba(255, 255, 255, 0.7);
    }
    #file-search-input:focus {
      background: rgba(255, 255, 255, 0.3) !important;
      border-color: rgba(255, 255, 255, 0.5) !important;
      color: #fff !important;
    }
    #file-search-input {
      color: #fff;
    }
  </style>
  
  <script>
    Dropzone.options.uploadForm = {
      paramName: "upload[]", // The name that will be used to transfer the file
      uploadMultiple: false,
      parallelUploads: 5,
      timeout:0,
      clickable: '#upload-button',
      dictDefaultMessage: lang['message-drop'],
      init: function() {
        var _this = this; // For the closure
        this.on('success', function(file, response) {
          if (response == 'OK') {
            loadFolders();
          } else {
            this.defaultOptions.error(file, response.join('\n'));
          }
        });
      },
      headers: {
        'Authorization': 'Bearer ' + getUrlParam('token')
      },
      acceptedFiles: "{{ implode(',', $helper->availableMimeTypes()) }}",
      maxFilesize: ({{ $helper->maxUploadSize() }} / 1000)
    }
  </script>
</body>
</html>
