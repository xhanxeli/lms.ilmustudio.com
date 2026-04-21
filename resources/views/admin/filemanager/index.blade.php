@extends('admin.layouts.app')

@section('content')
    <section class="section">
        <div class="section-header">
            <h1>{{ trans('admin/main.file_manager') }}</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="{{ getAdminPanelUrl() }}">{{ trans('admin/main.dashboard') }}</a></div>
                <div class="breadcrumb-item">{{ trans('admin/main.file_manager') }}</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>{{ trans('admin/main.search_files') }}</h4>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="{{ getAdminPanelUrl() }}/file-manager" class="mb-4">
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control" placeholder="{{ trans('admin/main.search_files_placeholder') }}" value="{{ $searchQuery }}">
                                    <div class="input-group-append">
                                        <button class="btn btn-primary" type="submit">
                                            <i class="fas fa-search"></i> {{ trans('admin/main.search') }}
                                        </button>
                                        @if(!empty($searchQuery))
                                            <a href="{{ getAdminPanelUrl() }}/file-manager" class="btn btn-secondary">
                                                <i class="fas fa-times"></i> {{ trans('admin/main.clear') }}
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </form>

                            @if(!empty($searchQuery))
                                @if(count($searchResults) > 0)
                                    <div class="alert alert-info">
                                        {{ trans('admin/main.found') }} {{ count($searchResults) }} {{ trans('admin/main.file') }}(s) {{ trans('admin/main.matching') }} "{{ $searchQuery }}"
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>{{ trans('admin/main.file_name') }}</th>
                                                    <th>{{ trans('admin/main.path') }}</th>
                                                    <th>{{ trans('admin/main.size') }}</th>
                                                    <th>{{ trans('admin/main.modified') }}</th>
                                                    <th>{{ trans('admin/main.actions') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($searchResults as $file)
                                                    <tr>
                                                        <td>
                                                            <i class="fas fa-file"></i> {{ $file['name'] }}
                                                        </td>
                                                        <td>
                                                            <code>{{ $file['path'] }}</code>
                                                        </td>
                                                        <td>{{ formatSizeUnits($file['size']) }}</td>
                                                        <td>{{ dateTimeFormat($file['modified'], 'j M Y H:i') }}</td>
                                                        <td>
                                                            <a href="{{ $file['url'] }}" target="_blank" class="btn btn-sm btn-info" title="{{ trans('admin/main.view') }}">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <button type="button" class="btn btn-sm btn-primary copy-url-btn" data-url="{{ url($file['url']) }}" title="{{ trans('admin/main.copy_url') }}">
                                                                <i class="fas fa-copy"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <div class="alert alert-warning">
                                        {{ trans('admin/main.no_files_found') }} "{{ $searchQuery }}"
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>

                    <div class="card mt-3">
                        <div class="card-header">
                            <h4>{{ trans('admin/main.file_manager') }}</h4>
                        </div>
                        <div class="card-body p-0">
                            <iframe id="fileManagerFrame" src="{{ url('/laravel-filemanager') }}" style="width: 100%; height: 800px; border: none;"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts_bottom')
    <script>
        $(document).ready(function() {
            // Copy URL to clipboard functionality
            $('.copy-url-btn').on('click', function() {
                var url = $(this).data('url');
                var $temp = $('<input>');
                $('body').append($temp);
                $temp.val(url).select();
                document.execCommand('copy');
                $temp.remove();
                
                // Show success message
                var $btn = $(this);
                var originalHtml = $btn.html();
                $btn.html('<i class="fas fa-check"></i>');
                $btn.removeClass('btn-primary').addClass('btn-success');
                
                setTimeout(function() {
                    $btn.html(originalHtml);
                    $btn.removeClass('btn-success').addClass('btn-primary');
                }, 2000);
            });
        });
    </script>
@endpush

