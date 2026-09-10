@props([
    /** Pixel size of the tile. The wordmark scales with it. */
    'size' => 20,
    /** Set false for the tile alone — the favicon-shaped surfaces, tight bars. */
    'wordmark' => true,
    /** A second line under the wordmark — the panels name themselves here
        ("Publisher", "Admin"). Null everywhere else. */
    'label' => null,
    /** 'accent' paints the label amber (the admin panel); 'muted' is the default. */
    'labelTone' => 'muted',
])

{{-- The mark, in one place.

     The selected Play-to-Gain monogram: two amber paths form the P/G flow and
     the ink path cuts the play-shaped counter. These are the exact generated
     paths used by the canonical asset and favicon set; only the viewBox crops
     the source artboard for small UI placements.

     Styling comes from brand-mark.css, imported by every bundle.

     Which panel you are in IS written here, and only here: the panels pass
     `label`, which sets a second line under the wordmark inside the lockup —
     one unit, no extra width, instead of a badge competing with the logo.

     On the offerwall it appears exactly once, in the footer attribution: the
     wall wears the publisher's brand and the design copy promises their
     players never see a logo that is not theirs. --}}

<span {{ $attributes->merge(['class' => 'brand-mark']) }}>
    <svg class="brand-mark-tile" width="{{ $size }}" height="{{ $size }}" viewBox="570 575 900 900" aria-hidden="true" focusable="false">
        <path fill="#F28C1A" d="M 600.205 996.47 C 600.113 994.988 600.123 993.808 600.087 992.345 C 597.609 892.684 634.602 793.538 705.852 722.266 C 765.327 661.913 844.278 624.607 928.667 616.981 C 953.294 614.738 987.757 615.607 1013.43 615.595 L 1143.58 615.719 L 1451.9 615.803 C 1403.34 667.623 1348.6 720.142 1298.68 771.503 L 873.985 1203.2 L 756.976 1321.71 C 701.657 1377.65 680.777 1406.41 599.956 1428.26 L 600.134 1361.72 C 600.135 1351.38 599.66 1336.57 600.239 1326.52 C 598.889 1288.75 600.134 1241.56 600.133 1203 L 600.205 996.47 Z"/>
        <path fill="#0A0B14" d="M 957.059 772.078 L 1120.28 771.886 L 1118.5 772.731 C 1109.66 776.853 1099.22 779.811 1089.94 783.893 C 1038.88 806.345 997.5 855.742 958.653 895.05 L 839.339 1015.95 C 813.163 1042.69 785.293 1072.32 758.348 1097.93 C 757.235 1080.87 758.961 1062.62 757.982 1045.82 C 750.3 914.02 815.269 790.952 957.059 772.078 Z"/>
        <path fill="#F28C1A" d="M 1163.51 990.935 L 1448.64 990.946 C 1449.45 1087.48 1440.38 1164.97 1388.6 1249.6 C 1337.28 1335.32 1253.67 1396.83 1156.55 1420.3 C 1050.56 1445.53 946.589 1424.31 854.645 1367.88 C 841.322 1359.14 828.417 1349.77 815.975 1339.81 C 854.659 1301.69 892.284 1261.91 931.041 1223.74 C 955.372 1242.73 970.023 1253.41 1000.25 1264.98 C 1051.96 1284.85 1109.46 1283.24 1159.97 1260.49 C 1210.96 1237.05 1250.82 1194.68 1271.1 1142.36 C 1243.44 1143.09 1213.61 1142.59 1185.74 1142.64 L 1013.74 1142.79 L 1163.51 990.935 Z"/>
    </svg>

    @if ($wordmark && $label)
        <span class="brand-mark-text">
            <span class="brand-mark-word" style="font-size: {{ round($size * 0.62, 1) }}px">{{ \App\Support\Identity::name() }}</span>
            <span @class(['brand-mark-label', 'brand-mark-label-accent' => $labelTone === 'accent'])>{{ $label }}</span>
        </span>
    @elseif ($wordmark)
        <span class="brand-mark-word" style="font-size: {{ round($size * 0.72, 1) }}px">{{ \App\Support\Identity::name() }}</span>
    @endif
</span>
