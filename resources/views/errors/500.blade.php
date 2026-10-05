@extends('errors::minimal')

@section('title', __('Server Error'))
@section('code', '500')
{{-- RAO-46. The message argument is a LITERAL and must stay one.
     This line used to interpolate the thrown error's own text. prepareResponse re-wraps a
     non-HttpException with that text when debug is off and renderHttpException hands the wrapper
     to this view, so the text printed here -- and a failing database statement carries its bound
     values inlined, so this page disclosed them. The fallback never fired, because a database
     error's text is never empty.
     The line below is the framework's own default, restored. That is the point worth keeping: the
     upstream default was always a literal and always safe, so this repository did not merely fail
     to extend its JSON-side decision to HTML -- it overrode a safe default and reversed it here.
     Nothing is invented, and it matches the title section above.
     Do NOT reintroduce that text here in any form -- not sanitised, not truncated, not behind a
     condition. A sanitiser is a weaker and different promise, and it would pass an output test
     whenever it happened to catch that test's own sample. A literal is checkable at the SITE,
     which lets the assertion be about this line rather than about one sample of output.
     This comment deliberately names no accessor: the requirement is that a plain search of these
     files returns nothing, and a comment quoting the old call would defeat that search. --}}
@section('message', __('Server Error'))
