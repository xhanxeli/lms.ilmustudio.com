<div class="row">
    <div class="col-12">
        <div class="form-group">
            <label class="input-label" for="mobile">{{ trans('auth.mobile') }} {{ !empty($optional) ? "(". trans('public.optional') .")" : '' }}:</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text" style="background-color: #6c757d; color: #fff;">+60</span>
                </div>
            <input name="mobile" type="text" class="form-control @error('mobile') is-invalid @enderror"
                       value="{{ old('mobile') }}" id="mobile" aria-describedby="mobileHelp" placeholder="e.g. 123456789">
            </div>
            <input type="hidden" name="country_code" value="+60">

            @error('mobile')
            <div class="invalid-feedback">
                {{ $message }}
            </div>
            @enderror
        </div>
    </div>
</div>
