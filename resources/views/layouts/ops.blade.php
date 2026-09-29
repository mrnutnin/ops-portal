<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <title>@yield('title', 'AlexiaSoft Ops')</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#f4f6f8;color:#17212b;font:16px/1.5 system-ui,sans-serif}
        main{width:min(100% - 2rem,72rem);margin:1.5rem auto}
        .card{background:#fff;border:1px solid #dce2e8;border-radius:12px;padding:1.5rem;box-shadow:0 4px 18px #17212b0a}
        h1{font-size:1.5rem;margin:0 0 .25rem}h2{font-size:1.15rem;margin:1.5rem 0 .75rem}p{margin:.4rem 0 1rem}.muted{color:#526170}
        label{display:block;font-weight:600;margin:.8rem 0 .3rem}input,select,textarea{width:100%;min-height:44px;border:1px solid #aab5c0;border-radius:7px;padding:.6rem .7rem;font:inherit}
        input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible,summary:focus-visible{outline:3px solid #2563eb;outline-offset:2px}
        button{min-height:44px;border:0;border-radius:7px;background:#17212b;color:#fff;padding:.6rem 1rem;font:inherit;font-weight:600;cursor:pointer}a{color:#164e83}
        .form-row{margin:1rem 0}.actions{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}.spread{justify-content:space-between}
        .card>.actions.spread{align-items:flex-start}.card>.actions.spread nav[aria-label="เมนู Admin"]{order:-1;width:100%;border-bottom:1px solid #e2e7eb;padding-bottom:.75rem;margin-bottom:.75rem}
        nav[aria-label="เมนู Admin"] a{padding:.4rem .55rem;border-radius:6px;text-decoration:none}nav[aria-label="เมนู Admin"] a[aria-current="page"]{background:#17212b;color:#fff}nav[aria-label="เมนู Admin"] a:hover{background:#e8edf1;color:#17212b}nav[aria-label="เมนู Admin"] a[aria-current="page"]:hover{background:#17212b;color:#fff}
        nav[aria-label="เมนู Admin"] form{margin-left:auto}nav[aria-label="เมนู Admin"] button{background:#e8edf1;color:#17212b}
        .notice{padding:.7rem 1rem;border-radius:7px;background:#e8f5ed;margin:1rem 0}.error{color:#a61b1b;margin:.25rem 0}
        .table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:.7rem;border-bottom:1px solid #e2e7eb;white-space:nowrap}th{font-size:.9rem}
        .small{font-size:.9rem}.audit-values{white-space:pre-wrap;overflow-wrap:anywhere;font:.85rem/1.4 ui-monospace,monospace;max-width:30rem}.checkbox{width:auto;min-height:auto}
        .summary{border:1px solid #dce2e8;border-radius:10px;padding:1rem;margin:1rem 0}.summary h2{margin:0 0 .5rem}
        .summary-list{display:flex;flex-wrap:wrap;gap:.5rem 1rem;margin:.5rem 0;list-style:none;padding:0}.summary-list li{background:#f4f6f8;border-radius:6px;padding:.35rem .65rem}
        .status-pill{display:inline-block;border-radius:999px;background:#e8f5ed;color:#165b35;padding:.2rem .65rem;font-weight:600}.status-pill.warning{background:#fff1d6;color:#754500}.status-pill.danger{background:#fce8e8;color:#9a1919}
        .ref{overflow-wrap:anywhere}.card>form:not(.actions),.form-section{max-width:46rem}details.form-section{border:1px solid #dce2e8;border-radius:8px;padding:.75rem 1rem;margin:1rem 0}summary{cursor:pointer;font-weight:600;padding:.3rem 0}
        td .actions input{width:min(14rem,100%)}fieldset{border:1px solid #dce2e8;border-radius:8px;margin:1.25rem 0;padding:.75rem 1rem}
        @media(max-width:600px){main{width:min(100% - 1rem,72rem);margin:.5rem auto}.card{padding:1rem}h1{font-size:1.3rem}nav[aria-label="เมนู Admin"] form{margin-left:0}.summary-list li{width:100%}}

    </style>
</head>
<body>
<main>
    @yield('content')
</main>
</body>
</html>
