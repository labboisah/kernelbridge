{!! html_entity_decode(strip_tags(str_ireplace(['</p>', '</li>', '</h1>', '</h2>', '</h3>', '<br>', '<br />'], ["\n\n", "\n", "\n\n", "\n\n", "\n\n", "\n", "\n"], $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8') !!}

Regards,
{{ config('app.name') }}
