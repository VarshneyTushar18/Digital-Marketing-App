@component('mail::message')
{{ $greeting }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@if ($caption !== '')
**{{ __('Caption:') }}**

{{ $caption }}

@endif

@if (! empty($mediaLinks))
**{{ __('Media') }}**

@foreach ($mediaLinks as $link)
[{{ $link['label'] }}]({{ $link['url'] }})

@endforeach
@endif

@component('mail::button', ['url' => $approveUrl, 'color' => 'primary'])
{{ __('Approve post') }}
@endcomponent

@component('mail::button', ['url' => $rejectUrl, 'color' => 'error'])
{{ __('Reject post') }}
@endcomponent

{{ $outroLine }}

{{ __('You can also review this item in the portal: :url', ['url' => $reviewUrl]) }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
@endcomponent
