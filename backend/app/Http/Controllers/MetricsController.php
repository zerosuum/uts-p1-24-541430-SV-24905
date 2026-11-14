<?php

namespace App\Http\Controllers;

use App\Support\HttpRequestCounter;
use Illuminate\Http\Response;

final class MetricsController extends Controller
{
    public function __invoke(HttpRequestCounter $counter): Response
    {
        return response($counter->render(), 200)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }
}
