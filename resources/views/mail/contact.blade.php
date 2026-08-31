{{-- text/plain, so the values are printed unescaped. `{{ }}` would turn every
     apostrophe in the message into &#039; on its way to a mailbox that has no
     HTML to protect. There is no markup context here to inject into. --}}
Topic:  {!! $topic !!}
From:   {!! $senderName !!} <{!! $senderEmail !!}>

{!! $body !!}
