<?php

use App\Http\Controllers\Legal\LegalDocumentController;
use Illuminate\Support\Facades\Route;

/*
| The Privacy Policy and the Terms of Service (ADR 0063). Public on purpose:
| they are read before anybody has an account — on the sign-up form, and by
| applicants on a careers page.
*/

Route::get('privacy', [LegalDocumentController::class, 'privacy'])->name('legal.privacy');
Route::get('terms', [LegalDocumentController::class, 'terms'])->name('legal.terms');
