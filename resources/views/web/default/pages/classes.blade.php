@extends(getTemplate().'.layouts.app2')

@push('styles_top')
    <link rel="stylesheet" href="/assets/default/vendors/swiper/swiper-bundle.min.css">
    <link rel="stylesheet" href="/assets/default/vendors/select2/select2.min.css">
@endpush

 

@section('content')

    <div class="container mt-30">
  
        <section class="mt-lg-50 pt-lg-20 mt-md-40 pt-md-40">
            <div class="mb-4">
                <form method="get" action="{{ url('/classes') }}" class="d-flex align-items-center" id="searchForm">
                    @foreach(request()->except(['search', 'page']) as $key => $value)
                        @if(is_array($value))
                            @foreach($value as $item)
                                <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                            @endforeach
                        @else
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    
                    <div class="form-group mb-0 mr-2 search-field-wrapper">
                        <input type="text" name="search" class="form-control"
                               placeholder="{{ trans('site.search_subjects') }}"
                               value="{{ $search ?? '' }}">
                    </div>
                    <button type="submit" class="btn btn-primary mr-2 search-button-wrapper">
                        {{ trans('admin/main.search') }}
                    </button>
                    @if(!empty($search))
                        <a href="{{ url('/classes') }}" class="btn btn-secondary">
                            <i class="fa fa-times"></i> {{ trans('admin/main.clear') }}
                        </a>
                    @endif
                </form>
            </div>
            
            <style>
                .menu-category > ul > li {
                    background-color: red;
                    color: white;
                    padding-left: 10px;
                    padding-top: 10px;
                    padding-bottom: 10px;
                }
                .search-field-wrapper {
                    width: 80%;
                    flex: 0 0 80%;
                }
                .search-button-wrapper {
                    width: 20%;
                    flex: 0 0 20%;
                }
                @media (max-width: 767.98px) {
                    #searchForm {
                        flex-wrap: nowrap;
                    }
                    .search-field-wrapper {
                        width: 80% !important;
                        flex: 0 0 80% !important;
                        margin-right: 0.5rem !important;
                    }
                    .search-button-wrapper {
                        width: 20% !important;
                        flex: 0 0 20% !important;
                        padding-left: 0.5rem;
                        padding-right: 0.5rem;
                    }
                }
            </style>
          <ul class="navbar-nav mr-auto d-flex align-items-left">
                    @if(!empty($categories) and count($categories))
                        <li class="mr-lg-25">
                            <div class="menu-category">
                                <ul>
                                    <li class="cursor-pointer user-select-none d-flex xs-categories-toggle">
                                        <i data-feather="grid" width="20" height="20" class="mr-10 d-none d-lg-block"></i>
                                        {{ trans('categories.categories') }}

                                        <ul class="cat-dropdown-menu">
                                            @foreach($categories as $category)
                                                <li>
                                                    <a href="{{ $category->getUrl() }}" class="{{ (!empty($category->subCategories) and count($category->subCategories)) ? 'js-has-subcategory' : '' }}">
                                                        <div class="d-flex align-items-center">
                                                            @if(!empty($category->icon))
                                                                <img src="{{ $category->icon }}" class="cat-dropdown-menu-icon mr-10" alt="{{ $category->title }} icon">
                                                            @endif

                                                            {{ $category->title }}
                                                        </div>

                                                        @if(!empty($category->subCategories) and count($category->subCategories))
                                                            <i data-feather="chevron-right" width="20" height="20" class="d-none d-lg-inline-block ml-10"></i>
                                                            <i data-feather="chevron-down" width="20" height="20" class="d-inline-block d-lg-none"></i>
                                                        @endif
                                                    </a>

                                                    @if(!empty($category->subCategories) and count($category->subCategories))
                                                        <ul class="sub-menu" data-simplebar @if((!empty($isRtl) and $isRtl)) data-simplebar-direction="rtl" @endif>
                                                            @foreach($category->subCategories as $subCategory)
                                                                <li>
                                                                    <a href="{{ $subCategory->getUrl() }}">
                                                                        @if(!empty($subCategory->icon))
                                                                            <img src="{{ $subCategory->icon }}" class="cat-dropdown-menu-icon mr-10" alt="{{ $subCategory->title }} icon">
                                                                        @endif

                                                                        {{ $subCategory->title }}
                                                                    </a>
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </li>
                                </ul>
                            </div>
                        </li>
                    @endif
            
            <div class="row mt-20">
                @foreach($webinars as $webinar)
                    <div class="col-12 col-lg-4 mt-20">
                        @include('web.default.includes.webinar.grid-card',['webinar' => $webinar])
                    </div>
                @endforeach
            </div>
            <div class="mt-50 pt-30">
                {{ $webinars->appends(request()->input())->links('vendor.pagination.panel') }}
            </div>
        </section>
    </div>

@endsection

@push('scripts_bottom')
    <script src="/assets/default/vendors/select2/select2.min.js"></script>
    <script src="/assets/default/vendors/swiper/swiper-bundle.min.js"></script>
<script src="/assets/default/js/parts/navbar.min.js"></script>
    <script src="/assets/default/js/parts/categories.min.js"></script>
@endpush
