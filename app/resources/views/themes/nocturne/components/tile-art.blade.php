@props(['name'])
{{--
    Contract: decorative vector background for a service tile, chosen by the service's
    icon name (music, folder, video, book, photo…); unknown names get a neutral pattern.
    Purely visual: aria-hidden, no text, drawn with currentColor so the theme sets the tone.
--}}
<svg viewBox="0 0 320 220" preserveAspectRatio="xMaxYMax slice" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" {{ $attributes->merge(['class' => 'ui-tile-art']) }}>
    @switch($name)
        @case('music')
            {{-- A record, an equaliser and two notes: music anywhere in the world. --}}
            <g stroke-width="1.6">
                <circle cx="268" cy="182" r="96" />
                <circle cx="268" cy="182" r="78" opacity=".7" />
                <circle cx="268" cy="182" r="60" opacity=".55" />
                <circle cx="268" cy="182" r="42" opacity=".45" />
                <circle cx="268" cy="182" r="16" />
                <circle cx="268" cy="182" r="4" fill="currentColor" />
                <path d="M200 120a96 96 0 0 1 40-26" opacity=".9" stroke-width="3" />
            </g>
            <g stroke-width="7" opacity=".55">
                <path d="M120 200v-28M138 200v-52M156 200v-36M174 200v-64M192 200v-22" />
            </g>
            <g stroke-width="2.2" opacity=".8">
                <path d="M214 46V18l34-7v28" /><circle cx="207" cy="46" r="7" /><circle cx="241" cy="39" r="7" />
                <path d="M164 92V72l20-4" /><circle cx="158" cy="92" r="6" />
            </g>
            @break
        @case('folder')
            {{-- Stacked folder, a document and a photo: personal files. --}}
            <g stroke-width="2">
                <path d="M170 92a10 10 0 0 1 10-10h40l14 16h66a10 10 0 0 1 10 10v104H170z" />
                <path d="M186 70a10 10 0 0 1 10-10h34l12 14h62a10 10 0 0 1 10 10v8" opacity=".5" />
            </g>
            <g stroke-width="1.8" opacity=".75">
                <rect x="198" y="120" width="48" height="62" rx="6" /><path d="M208 138h28M208 150h28M208 162h18" />
                <rect x="256" y="128" width="46" height="40" rx="6" /><circle cx="270" cy="142" r="5" /><path d="m258 166 14-12 10 8 8-6 12 10" />
            </g>
            @break
        @case('video')
            <g stroke-width="2"><rect x="150" y="70" width="150" height="100" rx="14" /><path d="m212 98 30 22-30 22z" /></g>
            <g stroke-width="1.6" opacity=".55"><path d="M150 186h150M150 200h96" /></g>
            @break
        @case('book')
            <g stroke-width="2"><path d="M170 80h56a18 18 0 0 1 18 18v110a14 14 0 0 0-14-14h-60z" /><path d="M318 80h-56a18 18 0 0 0-18 18v110a14 14 0 0 1 14-14h60z" /></g>
            <g stroke-width="1.6" opacity=".55"><path d="M184 104h40M184 120h40M184 136h28M264 104h40M264 120h40M264 136h28" /></g>
            @break
        @case('image')
            <g stroke-width="2"><rect x="160" y="70" width="140" height="110" rx="12" /><circle cx="196" cy="104" r="12" /><path d="m164 172 46-40 30 24 22-16 36 30" /></g>
            @break
        @default
            <g fill="currentColor" stroke="none" opacity=".6">
                @for ($row = 0; $row < 6; $row++)
                    @for ($col = 0; $col < 8; $col++)
                        <circle cx="{{ 170 + $col * 20 }}" cy="{{ 100 + $row * 20 }}" r="{{ 1.2 + ($col + $row) * 0.18 }}" />
                    @endfor
                @endfor
            </g>
    @endswitch
</svg>
