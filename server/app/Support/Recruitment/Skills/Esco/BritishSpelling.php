<?php

namespace App\Support\Recruitment\Skills\Esco;

use App\Support\Recruitment\Skills\Words;

/**
 * ESCO is written in British English; Philippine postings and résumés are
 * mostly written in American (ADR 0100). "call centre agent" must meet "call
 * center agent", and "paediatric phlebotomy" a candidate's "pediatric".
 *
 * Folded text in, the American spelling out. Only whole, listed words change,
 * so "exercise", "enterprise" and "premise" are left alone. Used for ESCO's
 * labels only; the catalogue lists both spellings itself where they matter.
 */
final class BritishSpelling
{
    /** Verbs spelled -ise in British English, in all their forms (-ised, -ising, -isation, -iser). */
    private const ISE = [
        'organ', 'recogn', 'priorit', 'special', 'util', 'optim', 'standard', 'final', 'custom', 'minim',
        'maxim', 'summar', 'categor', 'author', 'visual', 'emphas', 'critic', 'mobil', 'sanit', 'steril',
        'synthes', 'digit', 'harmon', 'item', 'memor', 'monet', 'normal', 'personal', 'public', 'real',
        'stabil', 'character', 'capital', 'central', 'computer', 'energ', 'familiar', 'hospital', 'immun',
        'local', 'modern', 'neutral', 'penal', 'rational', 'social', 'symbol', 'systemat', 'theor', 'verbal',
        'commercial', 'industrial', 'internal', 'apolog', 'global', 'initial', 'legal', 'motor',
        'decentral', 'subsid', 'jeopard', 'patron', 'scrutin', 'hypothes', 'econom', 'monopol', 'vapor',
    ];

    /** Words spelled -our in British English, and -or in American. */
    private const OUR = [
        'colo', 'behavio', 'labo', 'favo', 'hono', 'flavo', 'harbo', 'humo', 'neighbo', 'rumo', 'vapo',
        'odo', 'savo', 'armo', 'endeavo', 'vigo', 'rigo', 'tumo', 'valo', 'parlo',
    ];

    /** Words spelled -re in British English, and -er in American. */
    private const RE = ['cent', 'theat', 'met', 'lit', 'fib', 'calib', 'lust', 'sab', 'somb', 'spect', 'meag'];

    /** Whole words that differ otherwise. */
    private const WORDS = [
        'programme' => 'program', 'programmes' => 'programs', 'catalogue' => 'catalog', 'catalogues' => 'catalogs',
        'dialogue' => 'dialog', 'analogue' => 'analog', 'licence' => 'license', 'licences' => 'licenses',
        'defence' => 'defense', 'offence' => 'offense', 'practise' => 'practice', 'practised' => 'practiced',
        'practising' => 'practicing', 'jewellery' => 'jewelry', 'aluminium' => 'aluminum', 'grey' => 'gray',
        'mould' => 'mold', 'moulding' => 'molding', 'ageing' => 'aging', 'enrol' => 'enroll', 'enrolment' => 'enrollment',
        'fulfil' => 'fulfill', 'fulfilment' => 'fulfillment', 'skilful' => 'skillful', 'instalment' => 'installment',
        'analyse' => 'analyze', 'analysed' => 'analyzed', 'analysing' => 'analyzing', 'analyses' => 'analyzes',
        'paralyse' => 'paralyze', 'catalyse' => 'catalyze', 'dialyse' => 'dialyze', 'plough' => 'plow',
        'manoeuvre' => 'maneuver', 'storey' => 'story', 'storeys' => 'stories', 'sceptic' => 'skeptic',
    ];

    /** British doubled consonants before -ing, -ed, -er, -or: "modelling", "travelled", "counsellor". */
    private const DOUBLED_L = [
        'model', 'travel', 'label', 'cancel', 'counsel', 'fuel', 'signal', 'level', 'marshal', 'channel',
        'tunnel', 'panel', 'total', 'equal', 'jewel', 'dial', 'funnel', 'shovel',
    ];

    /** The medical "ae" and "oe" of British English: "paediatric", "anaesthesia", "oedema". */
    private const DIGRAPHS = [
        'paediatr' => 'pediatr', 'anaesthe' => 'anesthe', 'haemat' => 'hemat', 'haemo' => 'hemo',
        'haema' => 'hema', 'orthopaed' => 'orthoped', 'gynaecolog' => 'gynecolog', 'oedema' => 'edema',
        'oesophag' => 'esophag', 'foet' => 'fet', 'diarrhoea' => 'diarrhea', 'oestrogen' => 'estrogen',
        'leukaem' => 'leukem', 'encyclopaed' => 'encycloped', 'caesar' => 'cesar', 'anaem' => 'anem',
        'ischaem' => 'ischem', 'septicaem' => 'septicem', 'faec' => 'fec', 'coeliac' => 'celiac',
    ];

    /**
     * Folded text (as {@see Words::fold()}
     * gives it) in American spelling.
     */
    public static function american(string $folded): string
    {
        if ($folded === '') {
            return $folded;
        }

        $words = explode(' ', $folded);

        foreach ($words as $index => $word) {
            $words[$index] = self::word($word);
        }

        return implode(' ', $words);
    }

    private static function word(string $word): string
    {
        if (isset(self::WORDS[$word])) {
            return self::WORDS[$word];
        }

        foreach (self::DIGRAPHS as $british => $american) {
            if (str_starts_with($word, $british)) {
                return $american.substr($word, strlen($british));
            }
        }

        static $patterns = null;

        $patterns ??= [
            '/^('.implode('|', self::ISE).')is(e|es|ed|ing|ation|ations|er|ers)$/' => '$1iz$2',
            '/^('.implode('|', self::OUR).')ur(s|ed|ing|ful|less|able|ite|ites)?$/' => '$1r$2',
            '/^('.implode('|', self::RE).')red$/' => '$1ered',
            '/^('.implode('|', self::RE).')re(s)?$/' => '$1er$2',
            '/^('.implode('|', self::DOUBLED_L).')l(ing|ed|er|ers|or|ors)$/' => '$1$2',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $american = (string) preg_replace($pattern, $replacement, $word, 1, $count);

            if ($count > 0) {
                return $american;
            }
        }

        return $word;
    }
}
