<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\PageService;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PageController extends Controller
{
    public function __construct(protected PageService $service) {}

    public function show(string $slug): View
    {
        $page = $this->service->findBySlug($slug);

        if (! $page) {
            throw new NotFoundHttpException;
        }

        return view('content', compact('page'));
    }
}
