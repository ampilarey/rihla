<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ \App\Http\Middleware\SetLocale::isRtl($locale) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $locale === 'dv' ? 'އުމްރަހް ކުރުންނަށްޓަކައި' : 'How to Perform Umrah' }}</title>
    <style>
        @if($locale === 'dv')
            {{-- storage/fonts/Faruma.ttf has never existed. dompdf silently
                 fell back to a font with no Thaana glyphs, so the Dhivehi
                 guide downloaded as boxes — and unlike a web page, a PDF is
                 what a pilgrim carries with them. The real font ships at
                 public/fonts/A_faruma.ttf. --}}
            {{-- Two faces from one file, and the second one is the point.
                 A_Faruma ships Regular only. Without a bold face declared,
                 dompdf resolves <strong> and any font-weight:bold to a
                 *different family* — Helvetica-Bold — which has no Thaana
                 glyphs, and every bold Dhivehi word renders as a row of
                 question marks. It is silent: the document downloads, the
                 body text is perfect, and only the headings are ruined.

                 Thaana has no true bold, so pointing bold at the regular
                 file loses nothing that exists. --}}
            @font-face {
                font-family: 'A_Faruma';
                font-weight: normal;
                src: url('{{ public_path('fonts/A_faruma.ttf') }}') format('truetype');
            }
            @font-face {
                font-family: 'A_Faruma';
                font-weight: bold;
                src: url('{{ public_path('fonts/A_faruma.ttf') }}') format('truetype');
            }
            body { font-family: 'A_Faruma', 'MV Waheed', sans-serif; }
        @else
            body { font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        @endif
        
        body {
            margin: 0;
            padding: 20px;
            font-size: 12px;
            line-height: 1.4;
            color: {{ \App\Support\Brand::INK }};
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid {{ \App\Support\Brand::GOLD_ON_LIGHT }};
        }
        
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: {{ \App\Support\Brand::WINE }};
            margin-bottom: 10px;
        }
        
        .title {
            font-size: 20px;
            font-weight: bold;
            color: {{ \App\Support\Brand::INK }};
            margin-bottom: 10px;
        }
        
        .subtitle {
            font-size: 14px;
            color: {{ \App\Support\Brand::INK_MUTED }};
        }
        
        .toc {
            margin-bottom: 30px;
            page-break-after: always;
        }
        
        .toc-title {
            font-size: 16px;
            font-weight: bold;
            color: {{ \App\Support\Brand::WINE }};
            margin-bottom: 15px;
            border-bottom: 1px solid {{ \App\Support\Brand::BORDER }};
            padding-bottom: 5px;
        }
        
        .toc-item {
            margin-bottom: 8px;
            padding-left: 20px;
        }
        
        .toc-number {
            color: {{ \App\Support\Brand::GOLD_ON_LIGHT }};
            font-weight: bold;
        }
        
        .step {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        
        .step-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        /* Ink on gold, never white — the rule Brand::GOLD states and this
           did not follow. Measured, the shipped version was white on the
           gold at 1.49:1: a step number a pilgrim could not read on the
           document they carry. Ink on the same gold is 9.85:1.
           Hexes are deliberately not written out here — BrandColourTest
           scans this file as plain text, and a comment naming a retired
           colour would make the guard cry wolf. */
        .step-number {
            background: {{ \App\Support\Brand::GOLD }};
            color: {{ \App\Support\Brand::INK }};
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            margin-right: 15px;
        }
        
        .step-title {
            font-size: 16px;
            font-weight: bold;
            color: {{ \App\Support\Brand::INK }};
        }
        
        .step-content {
            margin-left: 45px;
        }
        
        .step-summary {
            margin-bottom: 10px;
            text-align: justify;
        }
        
        .step-details {
            margin-bottom: 10px;
            padding: 10px;
            background: {{ \App\Support\Brand::CREAM }};
            border-left: 3px solid {{ \App\Support\Brand::WINE }};
        }
        
        .step-reference {
            margin-bottom: 10px;
            padding: 10px;
            font-style: italic;
            color: {{ \App\Support\Brand::INK_MUTED }};
            border-left: 3px solid {{ \App\Support\Brand::GOLD_ON_LIGHT }};
        }

        .step-dua {
            margin-bottom: 10px;
            padding: 10px;
            background: {{ \App\Support\Brand::CREAM }};
            border-left: 3px solid {{ \App\Support\Brand::GOLD_ON_LIGHT }};
        }
        
        .step-fiqh {
            margin-bottom: 10px;
            padding: 10px;
            background: {{ \App\Support\Brand::CREAM }};
            /* Was a bright orange on a warm off-white, from no palette this
               site has ever had, measuring 2.69:1 against its own
               background. The panels are told apart by their rule and not
               their fill, so this one takes ink — wine, gold and ink being
               three a reader can actually tell apart. */
            border-left: 3px solid {{ \App\Support\Brand::INK }};
        }
        
        .step-checklist {
            margin-bottom: 10px;
        }
        
        .checklist-item {
            margin-bottom: 5px;
            padding-left: 20px;
        }
        
        .checklist-item:before {
            content: "☐ ";
            color: {{ \App\Support\Brand::GOLD_ON_LIGHT }};
            font-weight: bold;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid {{ \App\Support\Brand::BORDER }};
            text-align: center;
            font-size: 10px;
            color: {{ \App\Support\Brand::INK_MUTED }};
        }
        
        .page-number {
            text-align: center;
            margin-top: 20px;
            font-size: 10px;
            color: {{ \App\Support\Brand::INK_MUTED }};
        }
        
        @media print {
            .step { page-break-inside: avoid; }
            .toc { page-break-after: always; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">Rihla Travels</div>
        <div class="title">
            @if($locale === 'en')
                How to Perform Umrah
            @else
                އުމްރަހް ކުރުންނަށްޓަކައި
            @endif
        </div>
        <div class="subtitle">
            @if($locale === 'en')
                Complete Step-by-Step Guide
            @else
                ފުރުސްތައްތައްނަމަށްޓަކައި
            @endif
        </div>
    </div>

    <div class="toc">
        <div class="toc-title">
            @if($locale === 'en')
                Table of Contents
            @else
                ފިހުރިސްތުންތައް
            @endif
        </div>
        @foreach($guideSteps as $step)
            <div class="toc-item">
                <span class="toc-number">{{ $step->step_number }}.</span>
                {{ $step->title }}
            </div>
        @endforeach
    </div>

    @foreach($guideSteps as $step)
        <div class="step">
            <div class="step-header">
                <div class="step-number">{{ $step->step_number }}</div>
                <div class="step-title">{{ $step->title }}</div>
            </div>
            
            <div class="step-content">
                @if($step->summary)
                    <div class="step-summary">{{ $step->summary }}</div>
                @endif
                
                @if($step->details)
                    <div class="step-details">
                        <strong>
                            @if($locale === 'en')
                                Details:
                            @else
                                ތަފްސީލްތައް:
                            @endif
                        </strong><br>
                        {{ $step->details }}
                    </div>
                @endif
                
                @if($step->dua_text)
                    <div class="step-dua">
                        <strong>
                            @if($locale === 'en')
                                Dua:
                            @else
                                ޑުޢާ:
                            @endif
                        </strong><br>
                        {{ $step->dua_text }}
                    </div>
                @endif
                
                @if($step->fiqh_notes)
                    <div class="step-fiqh">
                        <strong>
                            @if($locale === 'en')
                                Fiqh Notes:
                            @else
                                ފިޤްހްތައް:
                            @endif
                        </strong><br>
                        @foreach ((array) $step->fiqh_notes as $fiqhNote)
                            {{ $fiqhNote }}<br>
                        @endforeach
                    </div>
                @endif

                @if($step->reference_text)
                    <div class="step-reference">
                        <strong>
                            @if($locale === 'en')
                                Reference:
                            @else
                                {{ __('guide.Reference') }}:
                            @endif
                        </strong><br>
                        {{ $step->reference_text }}
                    </div>
                @endif
                
                @if($step->checklist && is_array($step->checklist))
                    <div class="step-checklist">
                        <strong>
                            @if($locale === 'en')
                                Checklist:
                            @else
                                ޗެކްލިސްޓް:
                            @endif
                        </strong>
                        @foreach($step->checklist as $item)
                            <div class="checklist-item">{{ $item }}</div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endforeach

    <div class="footer">
        <div>
            @if($locale === 'en')
                Generated by Rihla Travels
            @else
                ރިހްލަ ޓްރެވަލްސްނަށްޓަކައިގަނޑުދިނުމަށްޓަކައި
            @endif
        </div>
        <div>
            @if($locale === 'en')
                Visit us at: rihla-travels.com
            @else
                ތިމަންމަތީންނަށްޓަކައިގަނޑުދިނުމަށްޓަކައި: rihla-travels.com
            @endif
        </div>
        <div>
            @if($locale === 'en')
                Generated on: {{ now()->format('F j, Y') }}
            @else
                ގަނޑުދިނުމަށްޓަކައިގަނޑުދިނުމަށްޓަކައި: {{ now()->format('F j, Y') }}
            @endif
        </div>
    </div>

    <div class="page-number">
        @if($locale === 'en')
            Page {PAGENO} of {nbpg}
        @else
            ސަފްހަތަކަށްޓަކައިގަނޑުދިނުމަށްޓަކައި {PAGENO} ނަމަށްޓަކައިގަނޑުދިނުމަށްޓަކައި {nbpg}
        @endif
    </div>
</body>
</html>
