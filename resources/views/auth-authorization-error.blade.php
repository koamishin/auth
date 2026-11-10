<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Authorization request could not be completed</title>
    <style>
        :root { color-scheme: light dark; font-family: ui-sans-serif, system-ui, sans-serif; }
        body { margin: 0; background: #f5f5f4; color: #292524; line-height: 1.6; }
        main { box-sizing: border-box; max-width: 44rem; margin: 5rem auto; padding: 0 1.5rem; }
        .label { color: #9a3412; font-size: .75rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        h1 { font-size: clamp(1.8rem, 5vw, 2.7rem); line-height: 1.15; letter-spacing: -.035em; }
        section { margin-top: 2rem; padding: 1.5rem; border: 1px solid #d6d3d1; border-radius: .75rem; background: #fff; }
        h2 { margin-top: 0; font-size: 1.1rem; }
        li + li { margin-top: .8rem; }
        code { overflow-wrap: anywhere; font-size: .9em; }
        @media (prefers-color-scheme: dark) { body { background: #1c1917; color: #f5f5f4; } .label { color: #fdba74; } section { background: #292524; border-color: #57534e; } }
    </style>
</head>
<body>
<main>
    <p class="label">Koamishin · Authorization</p>
    <h1>We couldn't start sign-in.</h1>
    @if ($reason === 'invalid_client')
        <p>The application credentials or callback URL could not be validated. No sign-in was started.</p>
    @elseif ($reason === 'invalid_scope')
        <p>The application requested an unsupported or malformed scope. No sign-in was started.</p>
    @elseif ($reason === 'pkce')
        <p>Public applications must use S256 PKCE. No sign-in was started.</p>
    @else
        <p>The authorization request is missing information or contains an invalid value. No sign-in was started.</p>
    @endif
    <section aria-labelledby="checks-heading">
        <h2 id="checks-heading">For the application developer</h2>
        <ol>
            <li>Check that the publishable key belongs to the intended application and environment, and that its OAuth client is active.</li>
            <li>Compare the requested <code>redirect_uri</code> with the callback registered for that environment in the Portal. Check the scheme, host, port, and path, and ensure the app serves that route.</li>
            <li>Check the requested scopes and use an authorization-code request with an S256 PKCE challenge for public clients.</li>
            <li>After correcting the configuration, restart the app if its environment changed and start a new sign-in from the application.</li>
        </ol>
        <p>If you are not the application developer, contact the application's support team.</p>
    </section>
</main>
</body>
</html>
