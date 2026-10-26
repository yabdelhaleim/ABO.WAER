<?php

namespace App\Services;

use Illuminate\Http\Request;

class IntroService
{
    /**
     * Cookie that mirrors "the intro already played in this browser
     * session" for the server. The overlay is a full-screen black layer,
     * so rendering it and then removing it from JavaScript paints a black
     * flash on every reload — the server has to know up front whether the
     * overlay belongs in the response at all.
     *
     * The value is written by intro.js as a plain (unencrypted) session
     * cookie so both sides read the same flag.
     */
    public const COOKIE_NAME = 'abo_intro_seen';

    /**
     * Whether the cinematic intro overlay should be rendered for this
     * request.
     *
     * Rules:
     *  - ?intro=0        → never render.
     *  - ?intro=1        → always render (replay).
     *  - ?intro=t<ms>    → always render (scrub to a timestamp).
     *  - otherwise       → render only while the intro has not played yet
     *                       in this browser session.
     */
    public static function shouldRender(Request $request): bool
    {
        $flag = $request->query('intro');
        $flag = is_string($flag) ? $flag : null;

        if ($flag === '0') {
            return false;
        }

        if ($flag === '1' || ($flag !== null && str_starts_with($flag, 't'))) {
            return true;
        }

        return ! $request->cookie(self::COOKIE_NAME);
    }
}
