{{--
    The storefront's maintenance page.

    Shown while the admin has the store in maintenance mode. The
    back office keeps working, so the person who turned the switch on
    can turn it off again.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $storeName }} — back shortly</title>
    <style>
        :root {
            --abo-ink: #111111;
            --abo-paper: #f4f3ef;
            --abo-lime: #d8ff3e;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 2rem;
            background: var(--abo-paper);
            color: var(--abo-ink);
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            text-align: center;
        }

        .abo-maintenance { max-width: 34rem; }

        .abo-maintenance__mark {
            display: inline-block;
            padding: 0.5rem 0.9rem;
            margin-bottom: 1.6rem;
            border: 2px solid var(--abo-ink);
            background: var(--abo-lime);
            font-weight: 700;
            letter-spacing: 0.22em;
        }

        .abo-maintenance h1 {
            margin: 0 0 0.8rem;
            font-size: clamp(1.9rem, 6vw, 3rem);
            line-height: 1.05;
            letter-spacing: -0.02em;
        }

        .abo-maintenance p {
            margin: 0;
            font-size: 1.05rem;
            line-height: 1.6;
            opacity: 0.75;
        }
    </style>
</head>
<body>
    <main class="abo-maintenance">
        <span class="abo-maintenance__mark">{{ $storeName }}</span>
        <h1>We are updating the store.</h1>
        <p>Back shortly — thank you for your patience.</p>
    </main>
</body>
</html>
