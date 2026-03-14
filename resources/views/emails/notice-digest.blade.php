<x-mail::message>
# {{ __('app.notice_digests.email.heading', locale: $locale) }}

{{ __('app.notice_digests.email.intro', [
    'pending' => $summary['pending_count'],
    'new' => $summary['new_relevant_count'],
    'completed' => $summary['completed_count'],
], locale: $locale) }}

@if(($summary['pending_count'] ?? 0) > 0)
## {{ __('app.notice_digests.email.sections.pending', locale: $locale) }}

@foreach(($summary['pending']['companies'] ?? []) as $company)
### {{ $company['name'] }}

@foreach(($company['items'] ?? []) as $item)
- **{{ $item['title'] }}**  
  {{ __('app.notice_digests.email.labels.scope', locale: $locale) }}: {{ $item['scope'] }}  
  {{ __('app.notice_digests.email.labels.issue_date', locale: $locale) }}: {{ $item['issue_date'] ?? '—' }}  
  {{ __('app.notice_digests.email.labels.due_date', locale: $locale) }}: {{ $item['compliance_due_at'] ?? '—' }}  
  [{{ __('app.notice_digests.email.labels.open_notice', locale: $locale) }}]({{ $item['url'] }})
@endforeach

@endforeach
@endif

@if(($summary['new_relevant_count'] ?? 0) > 0)
## {{ __('app.notice_digests.email.sections.new_relevant', locale: $locale) }}

@foreach(($summary['new_relevant']['companies'] ?? []) as $company)
### {{ $company['name'] }}

@foreach(($company['items'] ?? []) as $item)
- **{{ $item['title'] }}**  
  {{ __('app.notice_digests.email.labels.scope', locale: $locale) }}: {{ $item['scope'] }}  
  {{ __('app.notice_digests.email.labels.issue_date', locale: $locale) }}: {{ $item['issue_date'] ?? '—' }}  
  [{{ __('app.notice_digests.email.labels.open_notice', locale: $locale) }}]({{ $item['url'] }})
@endforeach

@endforeach
@endif

@if(($summary['completed_count'] ?? 0) > 0)
## {{ __('app.notice_digests.email.sections.completed', locale: $locale) }}

@foreach(($summary['completed']['companies'] ?? []) as $company)
### {{ $company['name'] }}

@foreach(($company['items'] ?? []) as $item)
- **{{ $item['title'] }}**  
  {{ __('app.notice_digests.email.labels.scope', locale: $locale) }}: {{ $item['scope'] }}  
  {{ __('app.notice_digests.email.labels.issue_date', locale: $locale) }}: {{ $item['issue_date'] ?? '—' }}  
  [{{ __('app.notice_digests.email.labels.open_notice', locale: $locale) }}]({{ $item['url'] }})
@endforeach

@endforeach
@endif

{{ __('app.notice_digests.email.outro', locale: $locale) }}
</x-mail::message>
