(function (global) {
    'use strict';

    const ALL_KEYS = [
        'who', 'what', 'where', 'when', 'why', 'how',
        'accuracy', 'clarity', 'conciseness', 'objectivity', 'completeness',
    ];

    const LABEL_MAP = {
        who: 'Who (Sino)',
        what: 'What (Ano)',
        where: 'Where (Saan)',
        when: 'When (Kailan)',
        why: 'Why (Bakit)',
        how: 'How (Paano)',
        accuracy: 'Accuracy (Tama)',
        clarity: 'Clarity (Malinaw)',
        conciseness: 'Conciseness (Hindi Paligoy-ligoy)',
        objectivity: 'Objectivity (Walang Panghuhusga)',
        completeness: 'Completeness (Kumpleto/Detalyado)',
    };

    const SHORT_VALID_TL = new Set(['ng', 'na', 'sa', 'si', 'ni', 'ko', 'mo', 'po', 'ba', 'ay', 'at', 'ka', 'pa', 'o', 'nang', 'daw', 'raw', 'din', 'rin']);

    function isGarbageWord(rawWord) {
        const w = String(rawWord || '').toLowerCase().replace(/^[^a-z0-9]+|[^a-z0-9]+$/g, '');
        if (!w) return true;
        if (/^(?:asdf+|qwer+|zxcv+|test+|sample|lorem|ipsum|xxx+|zzz+|blah+|none|n\/a)$/i.test(w)) {
            return true;
        }
        // Catch repeating keyboard-smash chunks like "asdsadasdasddasd"
        if (/([a-z]{2,4})\1{2,}/i.test(w) || /(.)\1{3,}/i.test(w)) {
            return true;
        }
        // Long pure-letter cluster without any vowel, or 5+ consecutive consonants
        if (/^[a-z]{4,}$/i.test(w) && (!/[aeiou]/i.test(w) || /[bcdfghjklmnpqrstvwxyz]{5,}/i.test(w))) {
            return true;
        }
        return false;
    }

    function isValidWord(rawWord) {
        const w = String(rawWord || '').toLowerCase().replace(/^[^a-z0-9]+|[^a-z0-9]+$/g, '');
        if (!w || isGarbageWord(w)) return false;
        if (SHORT_VALID_TL.has(w)) return true;
        if (/^\d+$/.test(w)) return true;
        return w.length >= 2 && /[aeiou]/i.test(w);
    }

    function evaluate(text, options = {}) {
        const val = String(text || '').trim();
        const extra = options.extraCoverage || {};
        const words = val ? val.split(/\s+/).filter(Boolean) : [];
        const validWords = words.filter(isValidWord);
        const garbageWords = words.filter((w) => /[a-z]{4,}/i.test(w) && isGarbageWord(w));

        const lettersOnly = val.replace(/[^A-Za-z]/g, '');
        const upperLetters = val.replace(/[^A-Z]/g, '');
        const isAllCaps = lettersOnly.length >= 16 && (upperLetters.length / lettersOnly.length) > 0.7;

        const hasProfanityOrInsult = /\b(?:puta|putangina|putang\s*ina|tangina|tang\s*ina|gago|gaga|tarantado|tarantada|ulol|bobo|tanga|bwisit|kupal|pakyu|hayop\s+ka|demonyo\s+ka|walanghiya|walang\s+hiya|fuck\w*|shit\w*|bitch\w*|asshole|idiot|stupid|moron)\b/i.test(val)
            || /!{3,}/.test(val);

        const uniqueValidRatio = validWords.length >= 12
            ? (new Set(validWords.map((w) => w.toLowerCase())).size / validWords.length)
            : 1;

        const accuracy = val.length >= 20
            && validWords.length >= 4
            && garbageWords.length === 0;

        const clarity = accuracy
            && val.length >= 35
            && validWords.length >= 6
            && !isAllCaps
            && (/[.,;!?\n]/.test(val) || words.length <= 22);

        const conciseness = accuracy
            && words.length <= 350
            && val.length <= 2500
            && uniqueValidRatio >= 0.34;

        const objectivity = accuracy && !hasProfanityOrInsult;

        const who = Boolean(extra.who) || (
            accuracy && (
                /\b(?:si|ni|kay|siya|sya|niya|nya|ako|ko|akin|saakin|kanya|kaniya|sila|nila|kami|namin|tayo|kapitbahay|kumpare|kumare|inirereklamo|nagsusumbong|mr\.?|ms\.?|mrs\.?|he|she|they|him|her|neighbor|tenant|landlord|driver|vendor|owner)\b/i.test(val)
                || /\b[A-Z][a-z]{2,}\s+[A-Z][a-z]{2,}\b/.test(val)
            )
        );

        const what = Boolean(extra.what) || (accuracy && validWords.length >= 4 && val.length >= 20);

        const where = Boolean(extra.where) || (
            accuracy && (
                /\b(?:purok|barangay|brgy\.?|tumana|marikina|kalye|street|st\.?|avenue|ave\.?|road|rd\.?|daan|kalsada|eskinita|kanto|tapat|harap|harapan|likod|loob|labas|bahay|tindahan|palengke|school|paaralan|opisina|trabaho|compound|gate|garahe|bakuran|bodega|covered\s+court|barangay\s+hall|house|home|store|market|office|building|unit|room|outside|inside|near|beside|dito|doon)\b/i.test(val)
                || /\bsa\s+(?!akin\b|iyo\b|kanya\b|kaniya\b|amin\b|atin\b|kanila\b|inyo\b|totoo\b|tingin\b|halip\b|katunayan\b|mismo\b|pagkakaalam\b)[a-zA-Z0-9]{3,}/i.test(val)
            )
        );

        const when = Boolean(extra.when) || (
            accuracy && (
                /\b(?:kahapon|kagabi|kanina|kaninang|ngayon|ngayong|noong|nung|nakaraang|bandang|alas[-\s]?\d+|umaga|hapon|gabi|tanghali|madaling\s+araw|buwan|linggo|araw|taon|minuto|oras|petsa|yesterday|today|tonight|morning|afternoon|evening|night|ago|last\s+\w+|enero|pebrero|marso|abril|mayo|hunyo|hulyo|agosto|setyembre|oktubre|nobyembre|disyembre|january|february|march|april|may|june|july|august|september|october|november|december|lunes|martes|miyerkules|huwebes|biyernes|sabado|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i.test(val)
                || /\b\d{1,2}:\d{2}\b|\b\d{1,2}\s?(?:am|pm)\b|\b\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b/i.test(val)
            )
        );

        const why = Boolean(extra.why) || (
            accuracy && (
                /\b(?:dahil|kasi|sapagkat|palibhasa|nag-ugat|nagsimula|nagumpisa|nag-umpisa|pinagmulan|dahilan|rason|kaya|because|since|triggered|reason|due\s+to|started\s+when)\b/i.test(val)
                || /dahilan\s*(?:\/|\:)/i.test(val)
            )
        );

        const how = Boolean(extra.how) || (
            accuracy && (
                /\b(?:paano|papaano|bigla|biglang|tapos|tas|pagkatapos|sabay|hanggang|kaya\s+naman|gamit|sa\s+pamamagitan|sinampal|sinuntok|tinulak|pinalo|hinagis|binato|sinigawan|pinagsalitaan|tinakot|binantaan|kinuha|inagaw|tinaguan|inataguan|pinuntahan|sumugod|pumasok|nagmura|pinagbabato|binayaran|hindi\s+binayaran|pinabayaan|sinira|by|using|suddenly|grabbed|hit|slapped|pushed|shouted|threatened|refused|blocked|broke|took|stole|how)\b/i.test(val)
            )
        );

        const whCount = [who, what, where, when, why, how].filter(Boolean).length;
        const completeness = accuracy && whCount === 6 && val.length >= 60 && validWords.length >= 12;

        const covered = {
            who, what, where, when, why, how,
            accuracy, clarity, conciseness, objectivity, completeness,
        };

        const warnings = {
            accuracy: val.length > 0 && !accuracy && (garbageWords.length > 0 || val.length >= 12),
            clarity: val.length > 0 && (isAllCaps || (words.length > 22 && !/[.,;!?\n]/.test(val))),
            conciseness: words.length > 350 || val.length > 2500 || (validWords.length >= 15 && uniqueValidRatio < 0.34),
            objectivity: hasProfanityOrInsult,
            completeness: false,
        };

        const metCount = ALL_KEYS.reduce((acc, k) => acc + (covered[k] ? 1 : 0), 0);

        let feedback = 'Maglagay ng malinaw na salaysay gamit ang 5 W\'s + How at ACCOC standards.';
        let feedbackState = 'neutral';

        if (warnings.objectivity) {
            feedback = 'Paalala sa Objectivity: Iwasan ang pagmumura o pang-iinsulto; ilahad lamang ang totoong pangyayari.';
            feedbackState = 'warning';
        } else if (warnings.accuracy) {
            feedback = 'Paalala sa Accuracy: Gumamit ng malinaw at totoong salita (iwasan ang random o dummy characters).';
            feedbackState = 'warning';
        } else if (warnings.clarity) {
            feedback = isAllCaps
                ? 'Paalala sa Clarity: Iwasan ang pagsulat nang puro ALL CAPS.'
                : 'Paalala sa Clarity: Hatiin ang mahabang salaysay gamit ang tuldok (.) o kuwit (,).';
            feedbackState = 'warning';
        } else if (warnings.conciseness) {
            feedback = 'Paalala sa Conciseness: Panatilihing direkta sa punto at huwag paulit-ulit ang salaysay.';
            feedbackState = 'warning';
        } else if (val.length > 0) {
            const missingWh = ['who', 'what', 'where', 'when', 'why', 'how'].filter((k) => !covered[k]);
            if (missingWh.length === 0 && metCount === ALL_KEYS.length) {
                feedback = 'Kumpleto at malinaw: Pasado ang salaysay sa lahat ng 5 W\'s + How at ACCOC standards.';
                feedbackState = 'complete';
            } else if (missingWh.length > 0) {
                const missingLabels = missingWh.map((k) => LABEL_MAP[k]).join(', ');
                feedback = `Kulayan ng berde ang natitira — idagdag sa kuwento ang: ${missingLabels}.`;
            } else {
                feedback = 'Magdagdag pa ng kaunting detalye upang makumpleto ang 11 / 11 checklist.';
            }
        }

        return {
            covered,
            warnings,
            metCount,
            totalCount: ALL_KEYS.length,
            feedback,
            feedbackState,
        };
    }

    const boundInstances = [];

    function renderResult(container, result) {
        if (!container) return;
        container.querySelectorAll('[data-story-chip]').forEach((chip) => {
            const key = chip.getAttribute('data-story-chip');
            if (!key) return;
            const isCovered = Boolean(result.covered[key]);
            const isWarn = !isCovered && Boolean(result.warnings[key]);
            chip.classList.toggle('is-covered', isCovered);
            chip.classList.toggle('is-warning', isWarn);
        });

        const scoreEl = container.querySelector('[data-story-score]');
        if (scoreEl) {
            scoreEl.textContent = `${result.metCount} / ${result.totalCount} met`;
            scoreEl.classList.toggle('is-complete', result.metCount === result.totalCount);
        }

        const feedbackEl = container.querySelector('[data-story-feedback]');
        if (feedbackEl) {
            feedbackEl.textContent = result.feedback;
            feedbackEl.classList.toggle('is-complete', result.feedbackState === 'complete');
            feedbackEl.classList.toggle('is-warning', result.feedbackState === 'warning');
        }
    }

    function bind(textareaInput, containerInput, options = {}) {
        const textarea = typeof textareaInput === 'string'
            ? document.querySelector(textareaInput)
            : textareaInput;
        const container = typeof containerInput === 'string'
            ? document.querySelector(containerInput)
            : containerInput;
        if (!textarea || !container) return null;

        let currentExtra = options.extraCoverage || null;
        const update = (overrideExtra) => {
            if (overrideExtra !== undefined) {
                currentExtra = overrideExtra;
            }
            const res = evaluate(textarea.value, { extraCoverage: currentExtra || {} });
            renderResult(container, res);
            return res;
        };

        textarea.addEventListener('input', () => {
            currentExtra = null;
            update();
        });
        textarea.addEventListener('change', () => update());
        textarea.addEventListener('blur', () => update());

        const instance = { textarea, container, update };
        boundInstances.push(instance);
        update();
        return instance;
    }

    function refresh(textareaSelector, extraCoverage) {
        const textarea = typeof textareaSelector === 'string'
            ? document.querySelector(textareaSelector)
            : textareaSelector;
        const inst = boundInstances.find((x) => x.textarea === textarea);
        if (inst) {
            return inst.update(extraCoverage);
        }
        return null;
    }

    function bindAll() {
        if (typeof document === 'undefined') return;
        document.querySelectorAll('[data-narrative-target]').forEach((container) => {
            const targetSelector = container.getAttribute('data-narrative-target');
            if (!targetSelector) return;
            const already = boundInstances.some((x) => x.container === container);
            if (!already) {
                bind(targetSelector, container);
            }
        });
    }

    const exported = { evaluate, bind, refresh, bindAll, ALL_KEYS };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = exported;
    } else {
        global.AgapNarrativeChecklist = exported;
        if (typeof document !== 'undefined') {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', bindAll);
            } else {
                bindAll();
            }
        }
    }
})(typeof window !== 'undefined' ? window : globalThis);
