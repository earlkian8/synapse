<?php

namespace App\Http\Controllers\Help;

use App\Http\Controllers\Controller;
use App\Http\Requests\Help\HelpForPageRequest;
use App\Http\Requests\Help\HelpSearchRequest;
use App\Support\Help\HelpCenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Help Center (ADR 0062): SYNAPSE's user manual, read for the signed-in
 * person. Everything it shows comes from {@see HelpCenter}, which leaves out
 * any article about a screen they cannot open — so an article that is not
 * theirs answers 404, exactly like one that does not exist.
 *
 * Reading the manual changes nothing, so nothing here is activity-logged.
 */
class HelpCenterController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('help/index', [
            'categories' => HelpCenter::categories($user),
            'featured' => HelpCenter::articles($user)->where('featured', true)->take(6)->values(),
        ]);
    }

    public function search(HelpSearchRequest $request): Response
    {
        $user = $request->user();
        $query = $request->searchTerm();
        $found = HelpCenter::search($user, $query);

        return Inertia::render('help/search', [
            'query' => $query,
            'results' => $found['results'],
            'partial' => $found['partial'],
            'terms' => $found['terms'],
            'categories' => HelpCenter::categories($user),
        ]);
    }

    /**
     * "Help for this page": the article that explains the page somebody was
     * on, or the Help Center's home when none does.
     */
    public function forPage(HelpForPageRequest $request): RedirectResponse
    {
        $article = HelpCenter::forPath($request->user(), $request->validated('path'));

        return redirect($article['href'] ?? route('help.index'));
    }

    public function category(Request $request, string $category): Response
    {
        $user = $request->user();
        $found = HelpCenter::category($user, $category);

        abort_if($found === null, 404);

        return Inertia::render('help/category', [
            'category' => $found,
            'categories' => HelpCenter::categories($user),
        ]);
    }

    public function show(Request $request, string $category, string $article): Response
    {
        $user = $request->user();
        $found = HelpCenter::article($user, $category, $article);

        abort_if($found === null, 404);

        return Inertia::render('help/article', [
            'article' => $found,
            'categories' => HelpCenter::categories($user),
        ]);
    }
}
