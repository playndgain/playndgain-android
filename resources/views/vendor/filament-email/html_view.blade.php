<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div wire:ignore>
        <iframe
            title="Email HTML"
            sandbox="allow-popups allow-popups-to-escape-sandbox"
            srcdoc="{{ $getState() }}"
            style="width: 100%; height: 75vh; border: 0;"
        ></iframe>
    </div>
</x-dynamic-component>
