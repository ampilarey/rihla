{{ $notice->headline }}

@if($notice->body)
{{ $notice->body }}

@endif
@if($notice->subjectReference())
Reference: {{ $notice->subjectReference() }}

@endif
{{ $business }}
