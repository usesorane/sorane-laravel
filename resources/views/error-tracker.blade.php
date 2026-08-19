{{--
    The browser capture script is NOT in this file. It lives once, in
    `ranetrace/ranetrace-php` at `resources/js/error-tracker.js`, and both SDKs
    render it from there. It used to live here too, inline and byte-identical for
    some 370 lines, and because each SDK's relay validates exactly what its own
    copy sent, a fix applied to one copy silently stranded the other.

    What stays here is what only Laravel can supply: the route the relay is
    mounted on, the values out of `config/ranetrace.php`, the CSRF token the
    `web` middleware group will check, and the Vite CSP nonce.
--}}
@if(config('ranetrace.javascript_errors.enabled'))
@php($ranetraceNonce = \Illuminate\Support\Facades\Vite::cspNonce())
<script @if($ranetraceNonce) nonce="{{ $ranetraceNonce }}" @endif>
{!! \Ranetrace\Php\JavaScript\CaptureScript::withConfig([
    'endpoint' => route('ranetrace.javascript-errors.store'),
    // The conditional wrapping this view is the enabled gate; the script re-reads
    // the flag because the framework-agnostic host has no such wrapper.
    'enabled' => true,
    'sampleRate' => (float) config('ranetrace.javascript_errors.sample_rate', 1.0),
    'captureConsoleErrors' => (bool) config('ranetrace.javascript_errors.capture_console_errors'),
    'maxBreadcrumbs' => (int) config('ranetrace.javascript_errors.max_breadcrumbs', 20),
    'ignoredErrors' => array_values((array) config(
        'ranetrace.javascript_errors.ignored_errors',
        \Ranetrace\Laravel\Http\Controllers\JavaScriptErrorController::DEFAULT_IGNORED_ERRORS,
    )),
    // This relay sits behind the `web` group's CSRF middleware, so the script is
    // given a token and sends `X-CSRF-TOKEN`. A host that configures no token
    // (the framework-agnostic SDK, whose relay checks Origin/Referer instead)
    // gets a script that never sends the header.
    'csrfToken' => csrf_token(),
]) !!}
</script>
@endif
