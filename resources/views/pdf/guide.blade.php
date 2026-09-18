<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'dv' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $locale === 'en' ? 'How to Perform Umrah' : 'އުމްރަހް ކުރުންނަށްޓަކައި' }}</title>
    <style>
        @if($locale === 'dv')
            @font-face {
                font-family: 'Faruma';
                src: url('{{ storage_path('fonts/Faruma.ttf') }}') format('truetype');
            }
            body { font-family: 'Faruma', 'MV Waheed', sans-serif; }
        @else
            body { font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        @endif
        
        body {
            margin: 0;
            padding: 20px;
            font-size: 12px;
            line-height: 1.4;
            color: #2E2621;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #D2A03C;
        }
        
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #8E2653;
            margin-bottom: 10px;
        }
        
        .title {
            font-size: 20px;
            font-weight: bold;
            color: #2E2621;
            margin-bottom: 10px;
        }
        
        .subtitle {
            font-size: 14px;
            color: #666;
        }
        
        .toc {
            margin-bottom: 30px;
            page-break-after: always;
        }
        
        .toc-title {
            font-size: 16px;
            font-weight: bold;
            color: #8E2653;
            margin-bottom: 15px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        
        .toc-item {
            margin-bottom: 8px;
            padding-left: 20px;
        }
        
        .toc-number {
            color: #D2A03C;
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
        
        .step-number {
            background: #D2A03C;
            color: white;
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
            color: #2E2621;
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
            background: #f8f9fa;
            border-left: 3px solid #8E2653;
        }
        
        .step-dua {
            margin-bottom: 10px;
            padding: 10px;
            background: #FBF6EC;
            border-left: 3px solid #D2A03C;
        }
        
        .step-fiqh {
            margin-bottom: 10px;
            padding: 10px;
            background: #fff8f0;
            border-left: 3px solid #ff6b35;
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
            color: #D2A03C;
            font-weight: bold;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        
        .page-number {
            text-align: center;
            margin-top: 20px;
            font-size: 10px;
            color: #999;
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
