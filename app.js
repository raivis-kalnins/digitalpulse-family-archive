/* WP My Family Tree — original, dependency-free React interface.
 * Copyright (c) 2026 Raivis Kalnins / DigitalPulse. GPL-3.0-or-later.
 * Uses the React runtime supplied by WordPress (wp.element).
 */
(function () {
    'use strict';
    if (!window.wp || !window.wp.element) return;
    const { createElement: h, createRoot, render, createPortal, useState, useEffect, useRef, useMemo, useCallback } = window.wp.element;
    const CONFIG = window.WPMFT_CONFIG || {};
    const WORDS = {
        en: {
            archive: 'A living family archive', title: 'Our family tree', subtitle: 'Every life is a leaf. Every story is part of our roots.',
            tree: 'Family tree', list: 'People', people: 'family members', families: 'families', search: 'Find someone in your family…', searchLabel: 'Search family members',
            searchHint: 'Type at least 2 characters to search.', noResults: 'No family members found.', close: 'Close', contact: 'Contact us', admin: 'Family administrator',
            roots: 'Connected by our roots', footer: 'Together, we keep our family story growing.', credit: 'Created by', language: 'Language',
            loading: 'Loading your family…', loadingPerson: 'Opening this life story…', loadingMore: 'Loading more…', loadMore: 'Load more people',
            retry: 'Try again', networkError: 'We could not connect. Please try again.', expired: 'Your session has ended. Please unlock the family archive again.',
            welcome: 'A place for our family', private: 'Our stories belong together.', privateIntro: 'Enter the family password to explore the people, photographs and memories that connect us.',
            password: 'Family password', showPassword: 'Show password', hidePassword: 'Hide password', remember: 'Remember me on this device for up to {days} days',
            unlock: 'Explore our family', unlocking: 'Opening…', lock: 'Lock family archive', protected: 'Private family archive',
            preparing: 'Your family archive is being prepared.', preparingHelp: 'The family administrator needs to set a password before visitors can enter.',
            helpAccess: 'Need access? Contact the family administrator.', zoomIn: 'Zoom in', zoomOut: 'Zoom out', fit: 'Fit tree to view', reset: 'Return to starting person',
            panHint: 'Drag the open space to move · Use + / − to zoom', panHintMobile: 'Drag to explore · Pinch or use + / − to zoom',
            ancestors: 'Ancestors', selected: 'Selected person', descendants: 'Descendants', partner: 'Partner / co-parent', sibling: 'Sibling',
            parents: 'Parents', children: 'Children', spouses: 'Partners / co-parents', siblings: 'Siblings', grandparent: 'Grandparent', grandchild: 'Grandchild', self: 'Selected person', relative: 'Family member',
            explore: 'Explore this family', moreBranch: 'Explore {name}’s family', openPerson: 'Read about {name}', familyOf: 'Family of',
            export: 'Save image', print: 'Print', exporting: 'Preparing image…', exported: 'Your family tree image is ready.', exportError: 'The image could not be created. Please try again.', exportPhotoError: 'A photograph could not be loaded, so the image was not saved. Please try again.',
            branchNotice: 'This view shows part of the family. Choose a person’s branch button to explore further.',
            noPeople: 'Your family story starts here.', noPeopleHelp: 'The administrator can add a person in WordPress Posts and choose the family category.',
            about: 'Life story', details: 'Life details', born: 'Born', died: 'Died', birthPlace: 'Birthplace', deathPlace: 'Place of death', location: 'Location', occupation: 'Occupation', maidenName: 'Birth surname',
            memories: 'Photographs & memories', gallery: 'Open photograph gallery', photos: 'photographs', photo: 'Photograph', previousPhoto: 'Previous photograph', nextPhoto: 'Next photograph', zoomPhoto: 'Zoom photograph',
            noBiography: 'There is more to this story. Contact the family administrator to share a memory or photograph.', connections: 'Family connections', noConnections: 'No family connections have been added yet.',
            contactHeading: 'Keep the story growing', contactIntro: 'Have a memory, a photograph or a correction to share? Send a message to the family administrator.',
            name: 'Your name', email: 'Email address', message: 'Your message', messagePlaceholder: 'Tell us what you would like to share…', send: 'Send message', sending: 'Sending…',
            sentTitle: 'Thank you for getting in touch.', sent: 'Your message has been sent to the family administrator.', contactNote: 'Your details are used only to respond to this message.',
            captcha: 'Please complete the security check.', captchaError: 'The security check could not load. Please try again.', required: 'Required',
            yearsUnknown: 'Dates not recorded', listIntro: 'The people who make up our family story.', allPeople: 'Explore the family archive',
            printReady: 'Your print window is ready.', countShown: '{count} people in this view', personCount: '{count} family members', clearSearch: 'Clear search',
            unknown: 'Not recorded', skip: 'Skip to family tree', selectedView: 'Choose how to explore', liveStatus: 'Family archive status'
        },
        lv: {
            archive: 'Dzīvs dzimtas arhīvs', title: 'Mūsu dzimtas koks', subtitle: 'Katra dzīve ir lapa. Katrs stāsts ir daļa no mūsu saknēm.',
            tree: 'Dzimtas koks', list: 'Cilvēki', people: 'dzimtas locekļi', families: 'ģimenes', search: 'Atrodi kādu savā dzimtā…', searchLabel: 'Meklēt dzimtas locekļus',
            searchHint: 'Lai meklētu, ievadi vismaz 2 rakstzīmes.', noResults: 'Neviens dzimtas loceklis nav atrasts.', close: 'Aizvērt', contact: 'Sazināties', admin: 'Dzimtas administrators',
            roots: 'Mūs vieno saknes', footer: 'Kopā turpinām mūsu dzimtas stāstu.', credit: 'Izstrādāja', language: 'Valoda',
            loading: 'Ielādē dzimtas koku…', loadingPerson: 'Atver dzīvesstāstu…', loadingMore: 'Ielādē…', loadMore: 'Ielādēt vairāk cilvēku',
            retry: 'Mēģināt vēlreiz', networkError: 'Neizdevās izveidot savienojumu. Lūdzu, mēģini vēlreiz.', expired: 'Tava sesija ir beigusies. Lūdzu, atver dzimtas arhīvu vēlreiz.',
            welcome: 'Vieta mūsu dzimtai', private: 'Mūsu stāsti pieder kopā.', privateIntro: 'Ievadi dzimtas paroli, lai iepazītu cilvēkus, fotogrāfijas un atmiņas, kas mūs vieno.',
            password: 'Dzimtas parole', showPassword: 'Rādīt paroli', hidePassword: 'Paslēpt paroli', remember: 'Atcerēties mani šajā ierīcē līdz {days} dienām',
            unlock: 'Iepazīt mūsu dzimtu', unlocking: 'Atver…', lock: 'Aizslēgt dzimtas arhīvu', protected: 'Privāts dzimtas arhīvs',
            preparing: 'Tavs dzimtas arhīvs tiek sagatavots.', preparingHelp: 'Dzimtas administratoram jāiestata parole, pirms viesi var piekļūt arhīvam.',
            helpAccess: 'Nepieciešama piekļuve? Sazinies ar dzimtas administratoru.', zoomIn: 'Pietuvināt', zoomOut: 'Attālināt', fit: 'Ietilpināt koku skatā', reset: 'Atgriezties pie sākuma cilvēka',
            panHint: 'Velc brīvo laukumu, lai pārvietotos · Pietuvini ar + / −', panHintMobile: 'Velc, lai izpētītu · Pietuvini ar pirkstiem vai + / −',
            ancestors: 'Senči', selected: 'Izvēlētais cilvēks', descendants: 'Pēcnācēji', partner: 'Dzīvesbiedrs / bērna vecāks', sibling: 'Brālis / māsa',
            parents: 'Vecāki', children: 'Bērni', spouses: 'Dzīvesbiedri / bērnu vecāki', siblings: 'Brāļi un māsas', grandparent: 'Vecvecāks', grandchild: 'Mazbērns', self: 'Izvēlētais cilvēks', relative: 'Dzimtas loceklis',
            explore: 'Izpētīt šo ģimeni', moreBranch: 'Izpētīt {name} ģimeni', openPerson: 'Lasīt par {name}', familyOf: 'Ģimenes skats',
            export: 'Saglabāt attēlu', print: 'Drukāt', exporting: 'Sagatavo attēlu…', exported: 'Tavs dzimtas koka attēls ir gatavs.', exportError: 'Neizdevās izveidot attēlu. Lūdzu, mēģini vēlreiz.', exportPhotoError: 'Neizdevās ielādēt fotogrāfiju, tādēļ attēls netika saglabāts. Lūdzu, mēģini vēlreiz.',
            branchNotice: 'Šeit redzama daļa no dzimtas. Izvēlies cilvēka zara pogu, lai izpētītu tālāk.',
            noPeople: 'Šeit sākas tavas dzimtas stāsts.', noPeopleHelp: 'Administrators var pievienot cilvēku WordPress sadaļā Raksti un izvēlēties dzimtas kategoriju.',
            about: 'Dzīvesstāsts', details: 'Dzīves dati', born: 'Dzimšanas datums', died: 'Miršanas datums', birthPlace: 'Dzimšanas vieta', deathPlace: 'Miršanas vieta', location: 'Dzīvesvieta', occupation: 'Nodarbošanās', maidenName: 'Dzimtais uzvārds',
            memories: 'Fotogrāfijas un atmiņas', gallery: 'Atvērt fotogaleriju', photos: 'fotogrāfijas', photo: 'Fotogrāfija', previousPhoto: 'Iepriekšējā fotogrāfija', nextPhoto: 'Nākamā fotogrāfija', zoomPhoto: 'Pietuvināt fotogrāfiju',
            noBiography: 'Šim stāstam vēl ir turpinājums. Sazinies ar dzimtas administratoru, lai pievienotu atmiņu vai fotogrāfiju.', connections: 'Ģimenes saites', noConnections: 'Ģimenes saites vēl nav pievienotas.',
            contactHeading: 'Turpināsim dzimtas stāstu', contactIntro: 'Vēlies dalīties ar atmiņu, fotogrāfiju vai precizējumu? Nosūti ziņu dzimtas administratoram.',
            name: 'Tavs vārds', email: 'E-pasta adrese', message: 'Tava ziņa', messagePlaceholder: 'Pastāsti, ar ko vēlies dalīties…', send: 'Nosūtīt ziņu', sending: 'Nosūta…',
            sentTitle: 'Paldies par tavu ziņu!', sent: 'Tava ziņa ir nosūtīta dzimtas administratoram.', contactNote: 'Tavi dati tiks izmantoti tikai atbildes sniegšanai.',
            captcha: 'Lūdzu, izpildi drošības pārbaudi.', captchaError: 'Drošības pārbaudi neizdevās ielādēt. Lūdzu, mēģini vēlreiz.', required: 'Obligāti',
            yearsUnknown: 'Datumi nav norādīti', listIntro: 'Cilvēki, kuri veido mūsu dzimtas stāstu.', allPeople: 'Iepazīsti dzimtas arhīvu',
            printReady: 'Drukas logs ir gatavs.', countShown: 'Šajā skatā ir {count} cilvēki', personCount: '{count} dzimtas locekļi', clearSearch: 'Notīrīt meklēšanu',
            unknown: 'Nav norādīts', skip: 'Pāriet uz dzimtas koku', selectedView: 'Izvēlies skatu', liveStatus: 'Dzimtas arhīva statuss'
        }
    };
    const PATHS = {
        tree: 'M12 21v-6M7 21h10M12 3l-5 6h3l-5 6h14l-5-6h3z',
        leaf: 'M20 3C10 2 3 6 4 13c1 7 10 8 14 2 2-3 2-8 2-12ZM4 21 16 9M9 16v-5M9 16h5',
        search: 'm21 21-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
        close: 'm6 6 12 12M6 18 18 6', plus: 'M12 5v14M5 12h14', minus: 'M5 12h14',
        fit: 'M9 3H3v6M15 3h6v6M3 15v6h6M21 15v6h-6', home: 'm3 10 9-7 9 7M5 9v12h14V9M9 21v-8h6v8',
        lock: 'M7 10V7a5 5 0 0 1 10 0v3M5 10h14v11H5zM12 14v3',
        mail: 'M3 5h18v14H3zM3 6l9 7 9-7', download: 'M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5',
        print: 'M6 8V3h12v5M6 17H3V9h18v8h-3M6 14h12v7H6zM17 11h1',
        people: 'M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM2 21v-2a7 7 0 0 1 14 0v2M17 4a4 4 0 0 1 0 8M18 15a5 5 0 0 1 4 5v1',
        arrow: 'M4 12h16m-6-6 6 6-6 6', left: 'm15 5-7 7 7 7', right: 'm9 5 7 7-7 7',
        pin: 'M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0ZM15 10a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        calendar: 'M4 5h16v16H4zM4 10h16M8 3v4M16 3v4', photo: 'M3 4h18v16H3zM3 17l6-6 4 4 3-3 5 5M16 8h.01',
        check: 'm5 12 4 4L19 6', eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        branch: 'M7 3v12a4 4 0 0 0 4 4h6M7 10h7a4 4 0 0 0 4-4V3M4 3h6M15 3h6m-7 13 3 3-3 3',
        info: 'M12 11v6M12 7h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z'
    };
    const leafPath = 'M48 2C40 10 38 3 32 9C26 16 20 7 16 15C12 23 6 18 6 28C7 35 1 37 5 45C10 52 5 57 14 62C20 66 14 72 25 75C33 77 31 87 42 87C48 87 47 96 50 98C53 90 59 90 62 84C67 80 74 85 76 75C79 69 87 71 88 62C89 54 97 53 94 44C92 36 99 32 91 25C86 20 89 14 80 14C72 14 75 6 66 7C57 9 56 0 48 2Z';
    const tr = (lang, key, vars) => (WORDS[lang] || WORDS.en)[key]?.replace(/\{(\w+)\}/g, (_, v) => String(vars?.[v] ?? '')) || key;
    const initial = name => (String(name || '').trim().split(/\s+/).slice(0, 2).map(n => Array.from(n)[0]).join('') || '·').toUpperCase();
    const year = v => (String(v || '').match(/\b\d{4}\b/) || [])[0] || '';
    const years = p => [year(p.born), year(p.died)].filter(Boolean).join(' – ');
    const dateLabel = (value, lang) => {
        if (!value) return '';
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            const d = new Date(value + 'T12:00:00Z');
            if (!Number.isNaN(d.getTime())) return new Intl.DateTimeFormat(lang === 'lv' ? 'lv-LV' : 'en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(d);
        }
        return String(value);
    };
    const cls = (...v) => v.filter(Boolean).join(' ');
    const enabled = (value, fallback = true) => value == null ? fallback : ![false, 0, '0', 'false', ''].includes(value);
    const validPhoto = url => {
        if (!url) return '';
        try { const u = new URL(url, location.href); return u.origin === location.origin && /^https?:$/.test(u.protocol) ? u.href : ''; } catch (_) { return ''; }
    };
    const mediaIdentity = url => {
        const safe = validPhoto(url); if (!safe) return '';
        const parsed = new URL(safe);
        return parsed.searchParams.get('action') === 'wpmft_media' ? 'attachment:' + parsed.searchParams.get('id') : safe;
    };
    function Icon({ name, size = 20, ...props }) {
        return h('svg', { width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.65, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true, focusable: false, ...props }, h('path', { d: PATHS[name] || PATHS.leaf }));
    }
    function OakMark({ size = 36 }) {
        return h('svg', { width: size, height: size, viewBox: '0 0 100 100', className: 'wpmft-oak-mark', 'aria-hidden': true },
            h('path', { d: leafPath, fill: 'currentColor' }), h('path', { d: 'M49 89 50 20M49 66 26 50M50 52 73 34M50 37 35 26', fill: 'none', stroke: '#f8f6eb', strokeWidth: 3, strokeLinecap: 'round', opacity: .75 }));
    }
    function Flag({ lang }) {
        return h('svg', { width: 19, height: 13, viewBox: '0 0 30 20', 'aria-hidden': true, className: 'wpmft-flag' }, lang === 'lv' ? [
            h('rect', { key: 'base', width: 30, height: 20, fill: '#8c2735' }), h('rect', { key: 'stripe', y: 8, width: 30, height: 4, fill: '#fff' })
        ] : [
            h('rect', { key: 'base', width: 30, height: 20, fill: '#243e70' }),
            h('path', { key: 'cross1', d: 'M0 0 30 20M30 0 0 20', stroke: '#fff', strokeWidth: 4 }),
            h('path', { key: 'cross2', d: 'M0 0 30 20M30 0 0 20', stroke: '#bd3e46', strokeWidth: 1.6 }),
            h('path', { key: 'cross3', d: 'M15 0v20M0 10h30', stroke: '#fff', strokeWidth: 7 }),
            h('path', { key: 'cross4', d: 'M15 0v20M0 10h30', stroke: '#bd3e46', strokeWidth: 4 })
        ]);
    }
    function Loader({ label, small = false }) {
        return h('div', { className: cls('wpmft-loading', small && 'is-small'), role: 'status' }, h('span', { className: 'wpmft-spinner', 'aria-hidden': true }), label && h('span', null, label));
    }
    function Message({ children, kind = 'error' }) {
        return children ? h('div', { className: 'wpmft-message is-' + kind, role: kind === 'error' ? 'alert' : 'status' }, h(Icon, { name: kind === 'error' ? 'info' : 'check', size: 18 }), h('span', null, children)) : null;
    }
    function Photo({ person, className = '', size = 64, leaf = false, lazy = true }) {
        const [failed, setFailed] = useState(false);
        const url = validPhoto(person?.photo || person?.thumb || person?.url);
        useEffect(() => setFailed(false), [url]);
        return h('span', { className: cls('wpmft-avatar', className, leaf && 'is-leaf'), style: { '--avatar-size': size + 'px' } },
            url && !failed ? h('img', { src: url, alt: '', loading: lazy ? 'lazy' : 'eager', decoding: 'async', draggable: false, onError: () => setFailed(true) }) : h('span', { className: 'wpmft-initials', 'aria-hidden': true }, initial(person?.name)),
            leaf && h('span', { className: 'wpmft-leaf-vein', 'aria-hidden': true }));
    }

    // Original vector artwork. This is decorative; relationship edges are drawn separately.
    function oakArt(width, height, id) {
        const cx = width / 2, base = height - 44, top = Math.max(90, height * .12), crownH = Math.max(460, height - 240), crownW = Math.min(width * .87, 1600);
        let s = '<defs><linearGradient id="' + id + '-bark" x1="0" y1="0" x2="1" y2="0"><stop stop-color="#596347"/><stop offset=".53" stop-color="#79815b"/><stop offset="1" stop-color="#495d42"/></linearGradient><radialGradient id="' + id + '-crown"><stop stop-color="#c1cc9c" stop-opacity=".29"/><stop offset="1" stop-color="#d8dfbd" stop-opacity=".08"/></radialGradient></defs>';
        s += '<ellipse cx="' + cx + '" cy="' + (base + 10) + '" rx="' + Math.min(380, width * .3) + '" ry="22" fill="#849364" opacity=".09"/>';
        s += '<ellipse cx="' + cx + '" cy="' + (top + crownH / 2) + '" rx="' + crownW / 2 + '" ry="' + crownH / 2 + '" fill="url(#' + id + '-crown)"/>';
        s += '<path d="M' + (cx - 51) + ' ' + base + ' Q' + (cx - 12) + ' ' + (base - 76) + ' ' + (cx - 17) + ' ' + (base - 183) + ' L' + (cx - 8) + ' ' + (top + 130) + ' Q' + cx + ' ' + (top + 95) + ' ' + (cx + 8) + ' ' + (top + 130) + ' L' + (cx + 21) + ' ' + (base - 183) + ' Q' + (cx + 9) + ' ' + (base - 63) + ' ' + (cx + 55) + ' ' + base + ' Q' + cx + ' ' + (base - 10) + ' ' + (cx - 51) + ' ' + base + 'Z" fill="url(#' + id + '-bark)" opacity=".27"/>';
        for (let side = -1; side <= 1; side += 2) {
            for (let b = 0; b < 4; b++) {
                const sy = base - 155 - b * (crownH * .14), ex = cx + side * crownW * (.35 - b * .045), ey = sy - crownH * (.23 + b * .012);
                s += '<path d="M' + cx + ' ' + sy + ' Q' + (cx + side * crownW * .14) + ' ' + (sy - 30) + ' ' + ex + ' ' + ey + '" stroke="#71805a" stroke-width="' + (16 - b * 2) + '" stroke-linecap="round" fill="none" opacity=".18"/>';
                s += '<path d="M' + (cx + side * crownW * .16) + ' ' + (sy - crownH * .1) + ' Q' + (cx + side * crownW * .2) + ' ' + (ey + 15) + ' ' + (cx + side * crownW * .2) + ' ' + (ey - 58) + '" stroke="#71805a" stroke-width="5" stroke-linecap="round" fill="none" opacity=".12"/>';
            }
        }
        for (let i = 0; i < 90; i++) {
            const a = i * 2.39996, r = Math.sqrt((i + 1) / 90), x = cx + Math.cos(a) * crownW * .48 * r, y = top + crownH * .48 + Math.sin(a) * crownH * .47 * r;
            const sz = 26 + (i * 11 % 29), rot = (i * 37 % 150) - 75;
            s += '<path d="' + leafPath + '" transform="translate(' + (x - sz / 2) + ' ' + (y - sz / 2) + ') rotate(' + rot + ' ' + sz / 2 + ' ' + sz / 2 + ') scale(' + sz / 100 + ')" fill="' + ['#7f9563', '#a2b17d', '#7c8e5d', '#b5bf91'][i % 4] + '" opacity="' + (.13 + (i % 4) * .025) + '"/>';
        }
        s += '<path d="M' + (cx - 25) + ' ' + (base - 7) + 'q-70 18-130 7M' + (cx + 20) + ' ' + (base - 7) + 'q70 18 130 7" fill="none" stroke="#70815a" opacity=".2" stroke-width="3" stroke-linecap="round"/>';
        return s;
    }
    function layoutTree(data) {
        const nodes = Array.isArray(data?.nodes) ? data.nodes.filter(n => n && Number.isFinite(Number(n.id))) : [];
        const grouped = new Map();
        nodes.forEach(n => { const g = Math.max(-8, Math.min(8, Number(n.generation) || 0)); if (!grouped.has(g)) grouped.set(g, []); grouped.get(g).push(n); });
        const levels = [...grouped.keys()].sort((a, b) => a - b), widest = Math.max(1, ...[...grouped.values()].map(v => v.length));
        const width = Math.max(1260, widest * 194 + 140), height = Math.max(780, levels.length * 190 + 120), points = new Map();
        levels.forEach((g, index) => {
            let row = grouped.get(g).slice();
            if (g === 0) {
                const root = row.find(n => Number(n.id) === Number(data.root));
                if (root) {
                    const others = row.filter(n => n !== root).sort((a, b) => (a.role === 'spouse' ? -1 : 0) - (b.role === 'spouse' ? -1 : 0));
                    row = [...others.slice(Math.ceil(others.length / 2)).reverse(), root, ...others.slice(0, Math.ceil(others.length / 2))];
                }
            }
            const gap = Math.min(230, (width - 140) / row.length), y = 80 + (height - 180 - levels.length * 160) / 2 + index * 190 + 80;
            row.forEach((node, i) => points.set(String(node.id), { ...node, x: width / 2 + (i - (row.length - 1) / 2) * gap, y }));
        });
        return { width, height, points, nodes: [...points.values()], edges: (data?.edges || []).filter(e => points.has(String(e.from)) && points.has(String(e.to))) };
    }
    function edgePath(edge, points) {
        const a = points.get(String(edge.from)), b = points.get(String(edge.to));
        if (!a || !b) return '';
        if (edge.type === 'spouse' || Math.abs(a.y - b.y) < 10) return 'M' + a.x + ',' + (a.y - 18) + ' Q' + ((a.x + b.x) / 2) + ',' + (a.y + 30) + ' ' + b.x + ',' + (b.y - 18);
        const sy = a.y + 50, ey = b.y - 51, mid = (sy + ey) / 2;
        return 'M' + a.x + ',' + sy + ' C' + a.x + ',' + mid + ' ' + b.x + ',' + mid + ' ' + b.x + ',' + ey;
    }

    let captchaPromise;
    function loadCaptcha() {
        if (window.hcaptcha) return Promise.resolve(window.hcaptcha);
        if (captchaPromise) return captchaPromise;
        captchaPromise = new Promise((resolve, reject) => {
            const timeout = setTimeout(() => { captchaPromise = null; reject(new Error('captcha')); }, 15000);
            window.wpmftCaptchaReady = () => { clearTimeout(timeout); resolve(window.hcaptcha); };
            let script = document.getElementById('wpmft-hcaptcha-sdk');
            if (!script) {
                script = document.createElement('script'); script.id = 'wpmft-hcaptcha-sdk'; script.src = 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=wpmftCaptchaReady'; script.async = true; script.defer = true;
                script.onerror = () => { clearTimeout(timeout); captchaPromise = null; script.remove(); reject(new Error('captcha')); };
                document.head.appendChild(script);
            }
        });
        return captchaPromise;
    }
    function Captcha({ siteKey, lang, onToken, resetKey }) {
        const node = useRef(null), callback = useRef(onToken);
        const [error, setError] = useState(''); callback.current = onToken;
        useEffect(() => {
            let dead = false, widget;
            setError(''); callback.current('');
            loadCaptcha().then(api => {
                if (dead || !node.current) return;
                widget = api.render(node.current, { sitekey: siteKey, hl: lang, theme: 'light', size: 'normal', callback: token => callback.current(token), 'expired-callback': () => callback.current(''), 'error-callback': () => { callback.current(''); setError(tr(lang, 'captchaError')); } });
            }).catch(() => !dead && setError(tr(lang, 'captchaError')));
            return () => { dead = true; if (widget !== undefined && window.hcaptcha) window.hcaptcha.remove(widget); };
        }, [siteKey, lang, resetKey]);
        return h('div', { className: 'wpmft-captcha' }, h('div', { ref: node }), error && h(Message, null, error));
    }

    let modalCount = 0, previousOverflow = '';
    function Modal({ children, title, onClose, wide = false, className = '', labelId }) {
        const node = useRef(null), close = useRef(onClose), lastFocus = useRef(null);
        close.current = onClose;
        useEffect(() => {
            lastFocus.current = document.activeElement;
            if (!modalCount++) { previousOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden'; }
            const dialog = node.current;
            const timer = setTimeout(() => { (dialog?.querySelector('[autofocus], button, input, [tabindex="0"]') || dialog)?.focus(); }, 0);
            function keydown(e) {
                const all = document.querySelectorAll('.wpmft-overlay');
                const top = document.querySelector('.wpmft-lightbox') || all[all.length - 1];
                if (top !== dialog?.parentElement) return;
                if (e.key === 'Escape') { e.preventDefault(); close.current(); return; }
                if (e.key !== 'Tab') return;
                const available = [...dialog.querySelectorAll('a[href],button,input,textarea,select,[tabindex="0"]')].filter(el => !el.disabled && el.getClientRects().length);
                if (!available.length) { e.preventDefault(); dialog.focus(); return; }
                const first = available[0], last = available[available.length - 1];
                if (e.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
            }
            document.addEventListener('keydown', keydown);
            return () => { clearTimeout(timer); document.removeEventListener('keydown', keydown); if (--modalCount === 0) document.body.style.overflow = previousOverflow; if (lastFocus.current?.isConnected) lastFocus.current.focus(); };
        }, []);
        return createPortal(h('div', { className: cls('wpmft-overlay', className), onMouseDown: e => e.target === e.currentTarget && onClose() },
            h('section', { ref: node, className: cls('wpmft-dialog', wide && 'is-wide'), role: 'dialog', 'aria-modal': true, 'aria-labelledby': labelId, 'aria-label': labelId ? undefined : title, tabIndex: -1 }, children)), document.body);
    }
    function CloseButton({ lang, onClick, dark = false }) {
        return h('button', { type: 'button', className: cls('wpmft-icon-button', 'wpmft-modal-close', dark && 'is-dark'), 'aria-label': tr(lang, 'close'), onClick }, h(Icon, { name: 'close', size: 23 }));
    }

    function Lightbox({ gallery, start, lang, onClose }) {
        const [index, setIndex] = useState(start || 0), [zoom, setZoom] = useState(false);
        const current = gallery[index], next = useCallback(delta => { setIndex(i => (i + delta + gallery.length) % gallery.length); setZoom(false); }, [gallery.length]);
        useEffect(() => {
            const listener = e => { if (e.key === 'ArrowLeft') { e.preventDefault(); next(-1); } if (e.key === 'ArrowRight') { e.preventDefault(); next(1); } };
            document.addEventListener('keydown', listener); return () => document.removeEventListener('keydown', listener);
        }, [next]);
        if (!current) return null;
        return h(Modal, { title: tr(lang, 'gallery'), onClose, wide: true, className: 'wpmft-lightbox' },
            h('div', { className: 'wpmft-lightbox-top' }, h('span', null, tr(lang, 'photo'), ' ', index + 1, ' / ', gallery.length), h('button', { type: 'button', className: 'wpmft-icon-button is-dark', 'aria-label': tr(lang, 'zoomPhoto'), 'aria-pressed': zoom, onClick: () => setZoom(!zoom) }, h(Icon, { name: zoom ? 'minus' : 'plus' }))),
            h(CloseButton, { lang, onClick: onClose, dark: true }),
            h('div', { className: cls('wpmft-lightbox-image', zoom && 'is-zoomed') }, h('img', { key: current.url, src: validPhoto(current.url), alt: current.alt || current.caption || tr(lang, 'photo'), onDoubleClick: () => setZoom(!zoom), draggable: false })),
            gallery.length > 1 && h('button', { type: 'button', className: 'wpmft-lightbox-arrow is-previous', onClick: () => next(-1), 'aria-label': tr(lang, 'previousPhoto') }, h(Icon, { name: 'left', size: 30 })),
            gallery.length > 1 && h('button', { type: 'button', className: 'wpmft-lightbox-arrow is-next', onClick: () => next(1), 'aria-label': tr(lang, 'nextPhoto') }, h(Icon, { name: 'right', size: 30 })),
            h('div', { className: 'wpmft-lightbox-caption' }, current.caption || current.alt || '', gallery.length > 1 && h('div', { className: 'wpmft-lightbox-dots' }, gallery.map((photo, i) => h('button', { type: 'button', key: photo.id || i, className: i === index ? 'is-active' : '', 'aria-label': tr(lang, 'photo') + ' ' + (i + 1), 'aria-current': i === index ? 'true' : undefined, onClick: () => { setIndex(i); setZoom(false); } })))));
    }
    function PersonDialog({ id, lang, api, onClose, onPerson, onRoot }) {
        const [person, setPerson] = useState(null), [error, setError] = useState(''), [retry, setRetry] = useState(0), [lightbox, setLightbox] = useState(null);
        const titleId = 'wpmft-person-title-' + id;
        useEffect(() => {
            const abort = new AbortController(); setPerson(null); setError(''); setLightbox(null);
            api('person', { id }, abort.signal).then(setPerson).catch(e => e.name !== 'AbortError' && setError(e.message));
            return () => abort.abort();
        }, [id, lang, retry, api]);
        const gallery = useMemo(() => {
            if (!person) return [];
            const photos = (person.gallery || []).filter(p => validPhoto(p.url));
            if (validPhoto(person.photo) && !photos.some(p => mediaIdentity(p.url) === mediaIdentity(person.photo) || mediaIdentity(p.thumb) === mediaIdentity(person.photo))) photos.unshift({ id: 'portrait', url: person.photo, thumb: person.photo, alt: person.name, caption: '' });
            return photos;
        }, [person]);
        const biographyClick = event => {
            const img = event.target.closest('img'); if (!img) return;
            const classId = (String(img.className).match(/wp-image-(\d+)/) || [])[1];
            const index = gallery.findIndex(p => classId && String(p.id) === classId || mediaIdentity(p.url) === mediaIdentity(img.src) || mediaIdentity(p.thumb) === mediaIdentity(img.src));
            if (index >= 0) { event.preventDefault(); setLightbox(index); }
        };
        const relationGroups = person ? ['parents', 'spouses', 'children', 'siblings'].filter(k => person.relations?.[k]?.length) : [];
        return h(Modal, { title: person?.name || tr(lang, 'loadingPerson'), labelId: person ? titleId : undefined, onClose, wide: true, className: 'wpmft-person-overlay' },
            h(CloseButton, { lang, onClick: onClose }),
            !person && !error && h('div', { className: 'wpmft-modal-loading' }, h(Loader, { label: tr(lang, 'loadingPerson') })),
            error && h('div', { className: 'wpmft-modal-loading' }, h(Message, null, error), h('button', { className: 'wpmft-button', type: 'button', onClick: () => setRetry(n => n + 1) }, tr(lang, 'retry'))),
            person && h('div', { className: 'wpmft-person' },
                h('div', { className: 'wpmft-person-cover' }, h('span', { className: 'wpmft-kicker' }, tr(lang, 'roots')), h('div', { className: 'wpmft-person-identity' },
                    h('button', { className: 'wpmft-portrait-button', type: 'button', disabled: !gallery.length, onClick: () => setLightbox(0), 'aria-label': tr(lang, 'gallery') }, h(Photo, { person, size: 132, leaf: true }), gallery.length > 0 && h('span', { className: 'wpmft-portrait-count' }, h(Icon, { name: 'photo', size: 14 }), gallery.length)),
                    h('div', null, h('h2', { id: titleId }, person.name), years(person) && h('p', { className: 'wpmft-life-years' }, years(person)), person.location && h('p', { className: 'wpmft-person-location' }, h(Icon, { name: 'pin', size: 16 }), person.location),
                        h('button', { type: 'button', className: 'wpmft-button is-primary', onClick: () => { onClose(); onRoot(person.id); } }, h(Icon, { name: 'branch', size: 18 }), tr(lang, 'explore'))))),
                h('div', { className: 'wpmft-person-body' },
                    h('div', { className: 'wpmft-life-facts' }, ['born', 'died', 'birthPlace', 'deathPlace', 'maidenName', 'occupation'].filter(k => person[k]).map(k => h('div', { className: 'wpmft-life-fact', key: k }, h('span', null, tr(lang, k)), h('strong', null, k === 'born' || k === 'died' ? dateLabel(person[k], lang) : person[k])))),
                    h('section', { className: 'wpmft-biography' }, h('h3', null, tr(lang, 'about')), person.bio ? h('div', { className: 'wpmft-prose', onClick: biographyClick, dangerouslySetInnerHTML: { __html: person.bio } }) : h('p', { className: 'wpmft-muted' }, tr(lang, 'noBiography'))),
                    gallery.length > 0 && h('section', { className: 'wpmft-gallery-section' }, h('div', { className: 'wpmft-section-heading' }, h('h3', null, tr(lang, 'memories')), h('span', null, gallery.length)), h('div', { className: 'wpmft-gallery' }, gallery.map((photo, i) => h('button', { type: 'button', key: photo.id || i, onClick: () => setLightbox(i), 'aria-label': (photo.alt || tr(lang, 'photo')) + ' — ' + (i + 1) + '/' + gallery.length }, h('img', { src: validPhoto(photo.thumb || photo.url), alt: photo.alt || '', loading: 'lazy', decoding: 'async' }), h('span', { 'aria-hidden': true }, h(Icon, { name: 'plus' })))))),
                    h('section', { className: 'wpmft-relations' }, h('h3', null, tr(lang, 'connections')), !relationGroups.length && h('p', { className: 'wpmft-muted' }, tr(lang, 'noConnections')), relationGroups.map(group => h('div', { key: group, className: 'wpmft-relation-group' }, h('h4', null, tr(lang, group)), h('div', { className: 'wpmft-relation-list' }, person.relations[group].map(p => h('button', { type: 'button', key: p.id, onClick: () => onPerson(p.id) }, h(Photo, { person: p, size: 42 }), h('span', null, h('strong', null, p.name), years(p) && h('small', null, years(p))), h(Icon, { name: 'right', size: 17 }))))))))),
            lightbox !== null && h(Lightbox, { gallery, start: lightbox, lang, onClose: () => setLightbox(null) }));
    }

    function ContactDialog({ lang, api, captcha, onClose }) {
        const [busy, setBusy] = useState(false), [error, setError] = useState(''), [sent, setSent] = useState(''), [token, setToken] = useState(''), [reset, setReset] = useState(0);
        const started = useRef(Math.floor(Date.now() / 1000)), alive = useRef(true);
        useEffect(() => () => { alive.current = false; }, []);
        async function submit(e) {
            e.preventDefault(); setError('');
            if (captcha?.contact && captcha.siteKey && !token) { setError(tr(lang, 'captcha')); return; }
            const form = new FormData(e.currentTarget);
            setBusy(true);
            try { const data = await api('contact', { name: form.get('name'), email: form.get('email'), message: form.get('message'), website: form.get('website'), started: started.current, 'h-captcha-response': token }); if (alive.current) setSent(data.message || tr(lang, 'sent')); }
            catch (err) { if (alive.current) { setError(err.message); setReset(v => v + 1); } }
            finally { if (alive.current) setBusy(false); }
        }
        return h(Modal, { title: tr(lang, 'contactHeading'), labelId: 'wpmft-contact-title', onClose, className: 'wpmft-contact-overlay' },
            h(CloseButton, { lang, onClick: onClose }), h('div', { className: 'wpmft-contact-content' },
                h('span', { className: 'wpmft-contact-symbol' }, h(Icon, { name: sent ? 'check' : 'mail', size: 28 })),
                h('h2', { id: 'wpmft-contact-title' }, sent ? tr(lang, 'sentTitle') : tr(lang, 'contactHeading')),
                h('p', { className: 'wpmft-contact-intro' }, sent || tr(lang, 'contactIntro')),
                sent ? h('button', { type: 'button', className: 'wpmft-button is-primary', onClick: onClose }, tr(lang, 'close')) :
                    h('form', { onSubmit: submit, className: 'wpmft-form' }, h(Message, null, error),
                        h('label', null, tr(lang, 'name'), h('input', { name: 'name', autoComplete: 'name', required: true, maxLength: 120, disabled: busy })),
                        h('label', null, tr(lang, 'email'), h('input', { name: 'email', type: 'email', autoComplete: 'email', required: true, maxLength: 190, disabled: busy })),
                        h('label', null, tr(lang, 'message'), h('textarea', { name: 'message', required: true, minLength: 10, maxLength: 5000, rows: 5, placeholder: tr(lang, 'messagePlaceholder'), disabled: busy })),
                        h('div', { className: 'wpmft-honeypot', 'aria-hidden': true }, h('label', null, 'Website', h('input', { name: 'website', tabIndex: -1, autoComplete: 'off' }))),
                        captcha?.contact && captcha.siteKey && h(Captcha, { siteKey: captcha.siteKey, lang, onToken: setToken, resetKey: reset }),
                        h('p', { className: 'wpmft-form-note' }, tr(lang, 'contactNote')),
                        h('button', { type: 'submit', className: 'wpmft-button is-primary is-full', disabled: busy }, busy ? h('span', { className: 'wpmft-spinner' }) : h(Icon, { name: 'mail', size: 18 }), tr(lang, busy ? 'sending' : 'send')))));
    }
    function Unlock({ boot, lang, onUnlock, onContact, contactEnabled, error: outsideError }) {
        const [password, setPassword] = useState(''), [show, setShow] = useState(false), [remember, setRemember] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState(''), [token, setToken] = useState(''), [reset, setReset] = useState(0);
        async function submit(e) {
            e.preventDefault(); setError('');
            if (boot.hcaptcha?.login && boot.hcaptcha.siteKey && !token) { setError(tr(lang, 'captcha')); return; }
            setBusy(true);
            try { await onUnlock(password, remember, token); setPassword(''); }
            catch (err) { setError(err.message); setReset(v => v + 1); }
            finally { setBusy(false); }
        }
        return h('section', { className: 'wpmft-gate' },
            h('div', { className: 'wpmft-gate-art', 'aria-hidden': true }, h('svg', { viewBox: '0 0 1100 900', dangerouslySetInnerHTML: { __html: oakArt(1100, 900, 'wpmft-gate-oak') } })),
            h('div', { className: 'wpmft-gate-card' }, h('span', { className: 'wpmft-private-badge' }, h(Icon, { name: 'lock', size: 14 }), tr(lang, 'protected')), h(OakMark, { size: 62 }),
                h('h2', null, tr(lang, 'welcome')), h('p', { className: 'wpmft-gate-lede' }, boot.configured === false ? tr(lang, 'preparing') : tr(lang, 'privateIntro')),
                h(Message, null, error || outsideError),
                boot.configured === false ? h('p', { className: 'wpmft-muted' }, tr(lang, 'preparingHelp')) :
                    h('form', { className: 'wpmft-form', onSubmit: submit }, h('label', null, tr(lang, 'password'), h('span', { className: 'wpmft-password-field' },
                        h('input', { type: show ? 'text' : 'password', value: password, onChange: e => setPassword(e.target.value), autoComplete: 'current-password', required: true, maxLength: 4096, disabled: busy }),
                        h('button', { type: 'button', onClick: () => setShow(!show), 'aria-label': tr(lang, show ? 'hidePassword' : 'showPassword'), 'aria-pressed': show }, h(Icon, { name: 'eye', size: 20 })))),
                        h('label', { className: 'wpmft-checkbox' }, h('input', { type: 'checkbox', checked: remember, onChange: e => setRemember(e.target.checked), disabled: busy }), h('span', null, tr(lang, 'remember', { days: Math.min(30, Number(boot.rememberDays) || 30) }))),
                        boot.hcaptcha?.login && boot.hcaptcha.siteKey && h(Captcha, { siteKey: boot.hcaptcha.siteKey, lang, onToken: setToken, resetKey: reset }),
                        h('button', { type: 'submit', className: 'wpmft-button is-primary is-full', disabled: busy }, tr(lang, busy ? 'unlocking' : 'unlock'), busy ? h('span', { className: 'wpmft-spinner' }) : h(Icon, { name: 'arrow', size: 18 }))),
                contactEnabled && h('button', { type: 'button', className: 'wpmft-access-help', onClick: onContact }, tr(lang, 'helpAccess'))));
    }

    function Search({ lang, api, onChoose }) {
        const [query, setQuery] = useState(''), [people, setPeople] = useState([]), [open, setOpen] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState(''), [selected, setSelected] = useState(-1);
        const root = useRef(null), input = useRef(null);
        useEffect(() => {
            const outside = e => { if (!root.current?.contains(e.target)) setOpen(false); };
            document.addEventListener('pointerdown', outside); return () => document.removeEventListener('pointerdown', outside);
        }, []);
        useEffect(() => {
            setSelected(-1); setError(''); setPeople([]);
            if (query.trim().length < 2) { setBusy(false); return; }
            const abort = new AbortController(); setBusy(true);
            const timer = setTimeout(() => api('search', { q: query.trim(), page: 1 }, abort.signal).then(data => { setPeople(data.people || []); setBusy(false); }).catch(e => { if (e.name !== 'AbortError') { setError(e.message); setBusy(false); } }), 250);
            return () => { clearTimeout(timer); abort.abort(); };
        }, [query, lang, api]);
        function choose(p) { onChoose(p.id); setQuery(''); setOpen(false); input.current?.focus(); }
        function keydown(e) {
            if (e.key === 'Escape') { setOpen(false); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); setOpen(true); setSelected(i => Math.min(i + 1, people.length - 1)); }
            if (e.key === 'ArrowUp') { e.preventDefault(); setSelected(i => Math.max(-1, i - 1)); }
            if (e.key === 'Enter' && selected >= 0 && people[selected]) { e.preventDefault(); choose(people[selected]); }
        }
        return h('div', { className: 'wpmft-search', ref: root }, h(Icon, { name: 'search', size: 20 }), h('input', { ref: input, type: 'search', role: 'combobox', 'aria-label': tr(lang, 'searchLabel'), 'aria-expanded': open, 'aria-controls': 'wpmft-search-results', 'aria-autocomplete': 'list', 'aria-activedescendant': selected >= 0 ? 'wpmft-search-option-' + selected : undefined, placeholder: tr(lang, 'search'), value: query, onChange: e => { setQuery(e.target.value); setOpen(true); }, onFocus: () => query && setOpen(true), onKeyDown: keydown, autoComplete: 'off', maxLength: 100 }),
            query && h('button', { type: 'button', className: 'wpmft-search-clear', 'aria-label': tr(lang, 'clearSearch'), onClick: () => { setQuery(''); input.current?.focus(); } }, h(Icon, { name: 'close', size: 16 })),
            open && h('div', { className: 'wpmft-search-panel', id: 'wpmft-search-results', role: 'listbox', 'aria-label': tr(lang, 'searchLabel') },
                query.trim().length < 2 ? h('p', null, tr(lang, 'searchHint')) : busy ? h(Loader, { small: true, label: tr(lang, 'loading') }) : error ? h(Message, null, error) : people.length ? people.map((p, i) => h('button', { type: 'button', role: 'option', 'aria-selected': i === selected, id: 'wpmft-search-option-' + i, key: p.id, className: i === selected ? 'is-active' : '', onClick: () => choose(p) }, h(Photo, { person: p, size: 40 }), h('span', null, h('strong', null, p.name), h('small', null, years(p))), h(Icon, { name: 'arrow', size: 16 }))) : h('p', null, tr(lang, 'noResults'))));
    }

    function Tree({ data, lang, busy, onPerson, onRoot, onHome, settings, exportRef }) {
        const layout = useMemo(() => layoutTree(data), [data]), viewport = useRef(null), lastLayout = useRef(null), pointers = useRef(new Map()), gesture = useRef(null), dimensions = useRef({ width: 1200, height: 650 });
        const [view, setView] = useState({ x: 0, y: 0, scale: .75 }), [dragging, setDragging] = useState(false), viewRef = useRef(view);
        viewRef.current = view;
        exportRef.current = layout;
        const fit = useCallback((initialView = false) => {
            const el = viewport.current; if (!el) return;
            const w = el.clientWidth, ht = el.clientHeight, center = layout.points.get(String(data?.root));
            const minX = Math.min(...layout.nodes.map(p => p.x - 104)), maxX = Math.max(...layout.nodes.map(p => p.x + 104));
            const minY = Math.min(...layout.nodes.map(p => p.y - 84)), maxY = Math.max(...layout.nodes.map(p => p.y + 84));
            const fitted = Math.max(.01, Math.min(1, (w - 34) / Math.max(500, maxX - minX), (ht - 34) / Math.max(440, maxY - minY)));
            // Keep names readable on large families; the Fit button remains an overview.
            const scale = initialView ? Math.max(w < 650 ? .85 : .74, fitted) : fitted;
            const focused = initialView && scale > fitted && center;
            setView({ scale, x: w / 2 - (focused ? center.x : (minX + maxX) / 2) * scale, y: ht / 2 - (focused ? center.y : (minY + maxY) / 2) * scale });
            dimensions.current = { width: w, height: ht };
        }, [layout, data?.root]);
        useEffect(() => {
            const timer = setTimeout(() => fit(true), 0); lastLayout.current = layout;
            return () => clearTimeout(timer);
        }, [layout, fit]);
        useEffect(() => {
            const el = viewport.current; if (!el || !window.ResizeObserver) return;
            const observer = new ResizeObserver(() => {
                const w = el.clientWidth, ht = el.clientHeight, previous = dimensions.current;
                if (Math.abs(w - previous.width) > 5 || Math.abs(ht - previous.height) > 5) { setView(v => ({ ...v, x: v.x + (w - previous.width) / 2, y: v.y + (ht - previous.height) / 2 })); dimensions.current = { width: w, height: ht }; }
            }); observer.observe(el); return () => observer.disconnect();
        }, []);
        const zoom = useCallback((factor, point) => {
            const el = viewport.current; if (!el) return;
            const rect = el.getBoundingClientRect(), p = point || { x: rect.width / 2, y: rect.height / 2 };
            setView(v => { const scale = Math.max(.01, Math.min(2.2, v.scale * factor)), ratio = scale / v.scale; return { scale, x: p.x - (p.x - v.x) * ratio, y: p.y - (p.y - v.y) * ratio }; });
        }, []);
        useEffect(() => {
            const el = viewport.current;
            const wheel = e => { if (!e.ctrlKey && !e.metaKey) return; e.preventDefault(); const r = el.getBoundingClientRect(); zoom(e.deltaY > 0 ? .92 : 1.08, { x: e.clientX - r.left, y: e.clientY - r.top }); };
            el?.addEventListener('wheel', wheel, { passive: false }); return () => el?.removeEventListener('wheel', wheel);
        }, [zoom]);
        function pointerDown(e) {
            if (e.target.closest('button,a,input')) return;
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            const point = { x: e.clientX, y: e.clientY }; pointers.current.set(e.pointerId, point);
            viewport.current.setPointerCapture(e.pointerId);
            const all = [...pointers.current.values()];
            gesture.current = all.length === 2 ? { type: 'pinch', distance: Math.hypot(all[0].x - all[1].x, all[0].y - all[1].y), view: { ...viewRef.current }, midpoint: { x: (all[0].x + all[1].x) / 2, y: (all[0].y + all[1].y) / 2 } } : { type: 'pan', point, view: { ...viewRef.current } };
            setDragging(true);
        }
        function pointerMove(e) {
            if (!pointers.current.has(e.pointerId) || !gesture.current) return;
            pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY });
            const all = [...pointers.current.values()], g = gesture.current;
            if (all.length >= 2 && g.type === 'pinch') {
                const rect = viewport.current.getBoundingClientRect(), scale = Math.max(.01, Math.min(2.2, g.view.scale * Math.hypot(all[0].x - all[1].x, all[0].y - all[1].y) / Math.max(1, g.distance))), ratio = scale / g.view.scale;
                const x = g.midpoint.x - rect.left, y = g.midpoint.y - rect.top;
                setView({ scale, x: (all[0].x + all[1].x) / 2 - rect.left - (x - g.view.x) * ratio, y: (all[0].y + all[1].y) / 2 - rect.top - (y - g.view.y) * ratio });
            } else if (g.type === 'pan') setView({ ...g.view, x: g.view.x + e.clientX - g.point.x, y: g.view.y + e.clientY - g.point.y });
        }
        function pointerUp(e) {
            pointers.current.delete(e.pointerId);
            if (pointers.current.size === 1) gesture.current = { type: 'pan', point: [...pointers.current.values()][0], view: { ...viewRef.current } };
            else { gesture.current = null; setDragging(false); }
        }
        function keyboard(e) {
            if (e.target !== viewport.current) return;
            if (e.key === '+' || e.key === '=') { e.preventDefault(); zoom(1.15); }
            if (e.key === '-') { e.preventDefault(); zoom(1 / 1.15); }
            if (e.key === 'Home') { e.preventDefault(); fit(); }
            const offsets = { ArrowLeft: [50, 0], ArrowRight: [-50, 0], ArrowUp: [0, 50], ArrowDown: [0, -50] };
            if (offsets[e.key]) { e.preventDefault(); const a = offsets[e.key]; setView(v => ({ ...v, x: v.x + a[0], y: v.y + a[1] })); }
        }
        const root = layout.points.get(String(data?.root));
        return h('section', { className: 'wpmft-tree-panel', 'aria-busy': busy },
            h('div', { className: 'wpmft-tree-context' }, h('div', null, h('span', { className: 'wpmft-kicker' }, tr(lang, 'familyOf')), h('h2', null, root?.name || tr(lang, 'title'))), h('span', { className: 'wpmft-view-count' }, tr(lang, 'countShown', { count: layout.nodes.length }))),
            h('div', { ref: viewport, className: cls('wpmft-tree-viewport', dragging && 'is-dragging'), tabIndex: 0, role: 'region', 'aria-label': tr(lang, 'tree'), 'aria-describedby': 'wpmft-pan-hint', style: { '--tree-height': Math.max(520, Math.min(1100, Number(settings.treeHeight) || 710)) + 'px' }, onPointerDown: pointerDown, onPointerMove: pointerMove, onPointerUp: pointerUp, onPointerCancel: pointerUp, onKeyDown: keyboard },
                h('div', { className: 'wpmft-tree-scene', style: { width: layout.width + 'px', height: layout.height + 'px', transform: 'translate(' + view.x + 'px,' + view.y + 'px) scale(' + view.scale + ')' } },
                    h('svg', { className: 'wpmft-oak-art', width: layout.width, height: layout.height, viewBox: '0 0 ' + layout.width + ' ' + layout.height, 'aria-hidden': true, dangerouslySetInnerHTML: { __html: oakArt(layout.width, layout.height, 'wpmft-oak-' + (data?.root || 0)) } }),
                    h('svg', { className: 'wpmft-relationship-lines', width: layout.width, height: layout.height, 'aria-hidden': true }, layout.edges.map((edge, i) => h('path', { key: i, d: edgePath(edge, layout.points), className: edge.type === 'spouse' ? 'is-spouse' : 'is-parent' }))),
                    layout.nodes.map(person => h('div', { key: person.id, className: cls('wpmft-tree-node', Number(person.id) === Number(data.root) && 'is-selected', person.died && 'is-remembered'), style: { left: person.x + 'px', top: person.y + 'px' } },
                        h('button', { className: 'wpmft-node-person', type: 'button', onClick: () => onPerson(person.id), 'aria-label': tr(lang, 'openPerson', { name: person.name }) }, h(Photo, { person, size: 78, leaf: true, lazy: false }), h('strong', null, person.name), h('span', { className: 'wpmft-node-dates' }, years(person) || (person.role && person.role !== 'self' ? tr(lang, person.role === 'parent' ? 'parents' : person.role === 'child' ? 'children' : person.role === 'spouse' ? 'partner' : person.role) : tr(lang, 'selected')))),
                        h('button', { type: 'button', className: cls('wpmft-node-branch', person.hasMore && 'has-more'), onClick: () => onRoot(person.id), title: tr(lang, 'moreBranch', { name: person.name }), 'aria-label': tr(lang, 'moreBranch', { name: person.name }) }, h(Icon, { name: 'branch', size: 16 }), person.hasMore && h('span', { className: 'wpmft-branch-dot', 'aria-hidden': true }))))),
                h('div', { className: 'wpmft-zoom-tools', onPointerDown: e => e.stopPropagation() }, [
                    ['plus', 'zoomIn', () => zoom(1.2)], ['minus', 'zoomOut', () => zoom(1 / 1.2)], ['fit', 'fit', () => fit()], ['home', 'reset', onHome]
                ].map(([icon, label, action]) => h('button', { type: 'button', key: icon, onClick: action, 'aria-label': tr(lang, label), title: tr(lang, label) }, h(Icon, { name: icon, size: 20 }))), h('span', { 'aria-hidden': true }, Math.round(view.scale * 100) + '%')),
                busy && h('div', { className: 'wpmft-tree-updating' }, h(Loader, { label: tr(lang, 'loading'), small: true }))),
            h('div', { className: 'wpmft-tree-bottom' }, h('div', { className: 'wpmft-legend' }, [['ancestors', 'ancestor'], ['selected', 'selected'], ['descendants', 'descendant']].map(([label, type]) => h('span', { key: type }, h('i', { className: 'is-' + type }), tr(lang, label)))), h('p', { id: 'wpmft-pan-hint' }, tr(lang, window.matchMedia?.('(max-width: 650px)').matches ? 'panHintMobile' : 'panHint'))),
            data?.truncated && h('p', { className: 'wpmft-branch-notice' }, h(Icon, { name: 'branch', size: 17 }), tr(lang, 'branchNotice')));
    }

    function Directory({ lang, api, onPerson, onRoot }) {
        const [people, setPeople] = useState([]), [page, setPage] = useState(1), [more, setMore] = useState(false), [busy, setBusy] = useState(true), [error, setError] = useState(''), [query, setQuery] = useState(''), [debounced, setDebounced] = useState('');
        useEffect(() => { const timer = setTimeout(() => { setDebounced(query.trim()); setPeople([]); setPage(1); }, 250); return () => clearTimeout(timer); }, [query]);
        useEffect(() => {
            const abort = new AbortController(); setBusy(true); setError('');
            api('search', { q: debounced, page }, abort.signal).then(data => { setPeople(previous => page === 1 ? (data.people || []) : [...previous, ...(data.people || []).filter(n => !previous.some(p => Number(p.id) === Number(n.id)))]); setMore(!!data.more); setBusy(false); }).catch(e => { if (e.name !== 'AbortError') { setError(e.message); setBusy(false); } });
            return () => abort.abort();
        }, [page, debounced, lang, api]);
        return h('section', { className: 'wpmft-directory', 'aria-busy': busy }, h('div', { className: 'wpmft-directory-top' }, h('div', null, h('span', { className: 'wpmft-kicker' }, tr(lang, 'allPeople')), h('h2', null, tr(lang, 'list')), h('p', null, tr(lang, 'listIntro'))), h('label', { className: 'wpmft-directory-search' }, h(Icon, { name: 'search', size: 18 }), h('input', { type: 'search', value: query, maxLength: 100, onChange: e => setQuery(e.target.value), placeholder: tr(lang, 'search'), 'aria-label': tr(lang, 'searchLabel') }))),
            h(Message, null, error),
            h('div', { className: 'wpmft-directory-grid' }, people.map(p => h('article', { key: p.id, className: 'wpmft-directory-card' }, h('button', { type: 'button', className: 'wpmft-directory-person', onClick: () => onPerson(p.id), 'aria-label': tr(lang, 'openPerson', { name: p.name }) }, h(Photo, { person: p, size: 80, leaf: true }), h('h3', null, p.name), h('p', null, years(p) || tr(lang, 'yearsUnknown'))), h('button', { type: 'button', className: 'wpmft-directory-branch', onClick: () => onRoot(p.id) }, h(Icon, { name: 'branch', size: 17 }), tr(lang, 'explore'))))),
            busy ? h(Loader, { label: tr(lang, 'loadingMore'), small: true }) : !people.length ? h('p', { className: 'wpmft-directory-empty' }, tr(lang, 'noResults')) : more && h('button', { type: 'button', className: 'wpmft-button wpmft-load-more', onClick: () => setPage(p => p + 1) }, tr(lang, 'loadMore'), h(Icon, { name: 'plus', size: 18 })));
    }

    const xml = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[c]);
    function wrapName(name, max = 23) {
        const words = String(name || '').split(/\s+/), lines = [''];
        words.forEach(word => { const i = lines.length - 1; if ((lines[i] + ' ' + word).trim().length > max && lines[i]) lines.push(word); else lines[i] = (lines[i] + ' ' + word).trim(); });
        return lines;
    }
    async function photoData(url) {
        const safe = validPhoto(url); if (!safe) return '';
        const abort = new AbortController(), timer = setTimeout(() => abort.abort(), 15000);
        let objectUrl;
        try {
            const response = await fetch(safe, { credentials: 'same-origin', cache: 'no-store', signal: abort.signal });
            if ([401, 403].includes(response.status)) { const error = new Error('session_expired'); error.code = 'session_expired'; throw error; }
            if (!response.ok || !/^image\//i.test(response.headers.get('Content-Type') || '')) throw new Error('photo_load');
            objectUrl = URL.createObjectURL(await response.blob());
            const img = await new Promise((resolve, reject) => { const image = new Image(); image.onload = () => resolve(image); image.onerror = () => reject(new Error('photo_load')); image.src = objectUrl; });
            const c = document.createElement('canvas'); c.width = 150; c.height = 150;
            const ctx = c.getContext('2d'), crop = Math.min(img.naturalWidth, img.naturalHeight);
            ctx.drawImage(img, (img.naturalWidth - crop) / 2, (img.naturalHeight - crop) / 2, crop, crop, 0, 0, 150, 150);
            return c.toDataURL('image/jpeg', .88);
        } catch (error) { if (error.code !== 'session_expired') error.code = 'photo_load'; throw error; }
        finally { clearTimeout(timer); if (objectUrl) URL.revokeObjectURL(objectUrl); }
    }
    async function makeSnapshot(layout, options) {
        const photoMap = new Map(), work = layout.nodes.slice();
        await Promise.all(Array.from({ length: Math.min(6, work.length) }, async () => { while (work.length) { const p = work.shift(); photoMap.set(String(p.id), await photoData(p.photo)); } }));
        const margin = 60, header = 174, footer = 110, width = layout.width + margin * 2, height = layout.height + header + footer;
        let body = '<rect width="100%" height="100%" fill="#f7f5eb"/><rect width="100%" height="6" fill="#762f3b"/>';
        body += '<text x="' + margin + '" y="58" fill="#66755c" font-family="Arial,sans-serif" font-size="15" letter-spacing="3">' + xml(tr(options.lang, 'archive').toUpperCase()) + '</text>';
        body += '<text x="' + margin + '" y="112" fill="#223f31" font-family="Georgia,serif" font-size="44">' + xml(options.title) + '</text>';
        body += '<text x="' + margin + '" y="148" fill="#677463" font-family="Arial,sans-serif" font-size="18">' + xml(options.subtitle) + '</text>';
        body += '<line x1="' + margin + '" x2="' + (width - margin) + '" y1="175" y2="175" stroke="#d8decc"/>';
        body += '<g transform="translate(' + margin + ' ' + header + ')">' + oakArt(layout.width, layout.height, 'export-oak');
        layout.edges.forEach(e => body += '<path d="' + edgePath(e, layout.points) + '" fill="none" stroke="' + (e.type === 'spouse' ? '#a77d6b' : '#8b9a71') + '" stroke-width="2.4"' + (e.type === 'spouse' ? ' stroke-dasharray="5 5"' : '') + '/>');
        layout.nodes.forEach(p => {
            const selected = Number(p.id) === Number(options.root), photo = photoMap.get(String(p.id)), clip = 'photo-' + p.id, x = p.x, y = p.y, lines = wrapName(p.name);
            body += '<g transform="translate(' + (x - 82) + ' ' + (y - 78) + ')"><rect width="164" height="151" rx="22" fill="' + (selected ? '#edf1df' : '#fffdf5') + '" stroke="' + (selected ? '#748c58' : '#d9dfcd') + '" stroke-width="' + (selected ? 2 : 1) + '"/>';
            body += '<defs><clipPath id="' + clip + '"><path d="M45 15C48 5 103 2 119 20C139 48 104 87 82 90C54 93 33 58 45 15Z"/></clipPath></defs>';
            body += '<path d="M45 15C48 5 103 2 119 20C139 48 104 87 82 90C54 93 33 58 45 15Z" fill="#dce4cb"/>';
            body += photo ? '<image href="' + photo + '" x="38" y="7" width="88" height="88" preserveAspectRatio="xMidYMid slice" clip-path="url(#' + clip + ')"/>' : '<text x="82" y="57" text-anchor="middle" fill="#506b45" font-family="Georgia,serif" font-size="28">' + xml(initial(p.name)) + '</text>';
            lines.slice(0, 2).forEach((line, i) => body += '<text x="82" y="' + (110 + i * 17) + '" text-anchor="middle" fill="#274331" font-family="Arial,sans-serif" font-weight="600" font-size="13">' + xml(line) + '</text>');
            body += '<text x="82" y="143" text-anchor="middle" fill="#687260" font-family="Arial,sans-serif" font-size="11">' + xml(years(p)) + '</text></g>';
        });
        body += '</g><line x1="' + margin + '" x2="' + (width - margin) + '" y1="' + (height - 99) + '" y2="' + (height - 99) + '" stroke="#d8decc"/>';
        body += '<text x="' + margin + '" y="' + (height - 60) + '" fill="#34533b" font-family="Georgia,serif" font-size="21">' + xml(tr(options.lang, 'footer')) + '</text>';
        body += '<text x="' + margin + '" y="' + (height - 30) + '" fill="#687460" font-family="Arial,sans-serif" font-size="15">' + xml([options.contactName || tr(options.lang, 'admin'), options.contactEmail].filter(Boolean).join(' · ')) + '</text>';
        body += '<text x="' + (width - margin) + '" y="' + (height - 30) + '" text-anchor="end" fill="#687460" font-family="Arial,sans-serif" font-size="13">' + xml(new Intl.DateTimeFormat(options.lang === 'lv' ? 'lv-LV' : 'en-GB', { dateStyle: 'long' }).format(new Date())) + '</text>';
        const svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '">' + body + '</svg>';
        const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }));
        try {
            const image = await new Promise((resolve, reject) => { const im = new Image(); im.onload = () => resolve(im); im.onerror = reject; im.src = url; });
            const canvas = document.createElement('canvas'), scale = Math.min(1.5, 4096 / width, 4096 / height);
            canvas.width = Math.round(width * scale); canvas.height = Math.round(height * scale); const context = canvas.getContext('2d'); if (!context) throw new Error('canvas');
            context.drawImage(image, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png')); if (!blob) throw new Error('blob');
            return { blob, data: canvas.toDataURL('image/png') };
        } finally { URL.revokeObjectURL(url); }
    }
    function printImage(data, title) {
        const frame = document.createElement('iframe'); frame.className = 'wpmft-print-frame'; frame.title = title; frame.setAttribute('aria-hidden', 'true'); document.body.appendChild(frame);
        const doc = frame.contentDocument; doc.open(); doc.write('<!doctype html><html><head><title>' + xml(title) + '</title><style>@page{size:landscape;margin:8mm}html,body{margin:0}img{display:block;max-width:100%;max-height:185mm;object-fit:contain;margin:auto}</style></head><body><img alt="' + xml(title) + '" src="' + data + '"></body></html>'); doc.close();
        const image = doc.querySelector('img');
        const ready = () => { frame.contentWindow.focus(); frame.contentWindow.print(); };
        if (image.complete) setTimeout(ready, 100); else image.onload = ready;
        frame.contentWindow.addEventListener('afterprint', () => frame.remove(), { once: true }); setTimeout(() => frame.remove(), 120000);
    }

    function App({ element }) {
        const urlLanguage = new URLSearchParams(location.search).get('wpmft_lang');
        const [lang, setLang] = useState(['en', 'lv'].includes(urlLanguage) ? urlLanguage : CONFIG.language === 'lv' ? 'lv' : 'en'), [boot, setBoot] = useState(null), [startupError, setStartupError] = useState(''), [reload, setReload] = useState(0), [rootId, setRootId] = useState(Number(element.dataset.root || CONFIG.rootId) || 0), [tree, setTree] = useState(null), [treeBusy, setTreeBusy] = useState(false), [treeError, setTreeError] = useState(''), [view, setView] = useState((element.dataset.view || CONFIG.settings?.defaultView) === 'list' ? 'list' : 'tree'), [personId, setPersonId] = useState(null), [contact, setContact] = useState(false), [exporting, setExporting] = useState(false), [notice, setNotice] = useState('');
        const csrf = useRef(''), language = useRef(lang), locked = useRef(null), exportLayout = useRef(null), initialRoot = useRef(rootId), alive = useRef(true), bootstrapSeq = useRef(0), didDeepLink = useRef(false), authorization = useRef(false);
        language.current = lang;
        const settings = { ...(CONFIG.settings || {}), ...(boot?.settings || {}) }, authorized = !!boot?.authorized;
        const contactEnabled = enabled(settings.contactEnabled);
        authorization.current = authorized;
        const title = (lang === 'lv' && settings.titleLv) || settings.title || tr(lang, 'title');
        const subtitle = (lang === 'lv' && settings.subtitleLv) || settings.subtitle || tr(lang, 'subtitle');
        locked.current = () => { setBoot(b => b ? { ...b, authorized: false } : b); setTree(null); setPersonId(null); exportLayout.current = null; setNotice(''); setTreeError(''); setStartupError(tr(language.current, 'expired')); };
        const api = useCallback(async (op, fields = {}, signal) => {
            const body = new URLSearchParams({ action: 'wpmft_api', op, lang: language.current, csrf: csrf.current });
            Object.entries(fields).forEach(([key, value]) => body.set(key, value == null ? '' : String(value)));
            let response, payload;
            try {
                response = await fetch(CONFIG.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body, signal });
                payload = await response.json();
            } catch (e) { if (e.name === 'AbortError') throw e; throw new Error(tr(language.current, 'networkError')); }
            if (!response.ok || !payload?.success) {
                const message = payload?.data?.message || tr(language.current, 'networkError'), code = payload?.data?.code || '';
                if (response.status === 401 || /^(locked|auth_required|session_expired|unauthorized|wpmft_locked)$/.test(code)) locked.current?.();
                const error = new Error(message); error.code = code; throw error;
            }
            return payload.data;
        }, []);
        const applyBoot = useCallback(data => {
            csrf.current = data.csrf || csrf.current;
            setBoot(data); setStartupError('');
            if (!initialRoot.current && data.rootId) { initialRoot.current = Number(data.rootId); setRootId(Number(data.rootId)); }
        }, []);
        useEffect(() => { alive.current = true; return () => { alive.current = false; }; }, []);
        useEffect(() => {
            const url = new URL(location.href); url.searchParams.set('wpmft_lang', lang);
            history.replaceState(history.state, '', url);
            if (CONFIG.standalone || document.body.classList.contains('wpmft-standalone')) document.documentElement.lang = lang;
        }, [lang]);
        useEffect(() => {
            if (!authorized || didDeepLink.current) return;
            didDeepLink.current = true;
            const id = Number(new URLSearchParams(location.search).get('person'));
            if (Number.isInteger(id) && id > 0) setPersonId(id);
        }, [authorized]);
        useEffect(() => {
            const abort = new AbortController(), seq = ++bootstrapSeq.current;
            api('bootstrap', {}, abort.signal).then(data => { if (seq === bootstrapSeq.current) applyBoot(data); }).catch(e => e.name !== 'AbortError' && setStartupError(e.message));
            return () => abort.abort();
        }, [api, applyBoot, lang, reload]);
        useEffect(() => {
            if (!authorized) { setTree(null); setPersonId(null); exportLayout.current = null; return; }
            const abort = new AbortController(); setTreeBusy(true); setTreeError('');
            api('tree', { root: rootId, depth: 2 }, abort.signal).then(data => { setTree(data); setTreeBusy(false); if (!initialRoot.current && data.root) initialRoot.current = Number(data.root); }).catch(e => { if (e.name !== 'AbortError') { setTreeError(e.message); setTreeBusy(false); } });
            return () => abort.abort();
        }, [authorized, rootId, lang, reload, api]);
        useEffect(() => {
            if (!authorized) return;
            const recheck = () => { if (document.visibilityState === 'visible') api('bootstrap').then(data => { csrf.current = data.csrf || csrf.current; if (!data.authorized) locked.current?.(); }).catch(() => {}); };
            document.addEventListener('visibilitychange', recheck); return () => document.removeEventListener('visibilitychange', recheck);
        }, [authorized, api]);
        useEffect(() => { if (!notice) return; const timer = setTimeout(() => setNotice(''), 6000); return () => clearTimeout(timer); }, [notice]);
        const chooseRoot = id => { setRootId(Number(id)); setView('tree'); setPersonId(null); if (Number(id) === rootId) setReload(v => v + 1); };
        async function unlock(password, remember, token) { const result = await api('unlock', { password, remember: remember ? '1' : '0', 'h-captcha-response': token }); applyBoot(result); }
        async function logout() {
            try { await api('logout'); locked.current(); setStartupError(''); const data = await api('bootstrap'); applyBoot(data); }
            catch (e) { locked.current(); setStartupError(e.message); }
        }
        async function snapshot(print) {
            if (!exportLayout.current || !authorized) return;
            setExporting(true); setNotice('');
            try {
                const result = await makeSnapshot(exportLayout.current, { title, subtitle, lang, contactName: settings.contactName, contactEmail: settings.contactEmail, root: tree?.root });
                if (!alive.current || !authorization.current) return;
                if (print) { printImage(result.data, title); setNotice(tr(lang, 'printReady')); }
                else { const url = URL.createObjectURL(result.blob), a = document.createElement('a'); a.href = url; a.download = 'family-tree-' + new Date().toISOString().slice(0, 10) + '.png'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000); setNotice(tr(lang, 'exported')); }
            } catch (error) { if (error.code === 'session_expired') locked.current(); else setTreeError(tr(lang, error.code === 'photo_load' ? 'exportPhotoError' : 'exportError')); }
            finally { if (alive.current) setExporting(false); }
        }
        return h('div', { className: 'wpmft-shell', lang, style: { '--wpmft-accent': /^#[0-9a-f]{6}$/i.test(settings.accent || '') ? settings.accent : '#355b3e', '--wpmft-bg': /^#[0-9a-f]{6}$/i.test(settings.background || '') ? settings.background : '#f7f5ec' } },
            h('a', { className: 'wpmft-skip-link', href: '#wpmft-main' }, tr(lang, 'skip')),
            h('header', { className: 'wpmft-header' }, h('div', { className: 'wpmft-header-inner' }, h('a', { href: '#wpmft-main', className: 'wpmft-brand', onClick: e => { e.preventDefault(); if (authorized) chooseRoot(initialRoot.current); else document.getElementById('wpmft-main')?.focus(); } }, h('span', { className: 'wpmft-brand-mark' }, h(OakMark, { size: 33 })), h('span', null, h('strong', null, title), h('small', null, tr(lang, 'archive')))),
                h('div', { className: 'wpmft-header-actions' }, enabled(settings.languageSwitch) && h('div', { className: 'wpmft-languages', role: 'group', 'aria-label': tr(lang, 'language') }, ['en', 'lv'].map(code => h('button', { type: 'button', key: code, lang: code, className: cls(lang === code && 'is-active'), 'aria-pressed': lang === code, onClick: () => setLang(code), 'aria-label': code === 'lv' ? 'Latviešu' : 'English' }, h(Flag, { lang: code }), h('span', null, code === 'lv' ? 'LV' : 'EN')))),
                    authorized && boot?.requiresPassword && h('button', { type: 'button', className: 'wpmft-icon-button wpmft-lock', onClick: logout, title: tr(lang, 'lock'), 'aria-label': tr(lang, 'lock') }, h(Icon, { name: 'lock', size: 19 }))))),
            h('main', { id: 'wpmft-main', className: 'wpmft-main', tabIndex: -1 }, h('section', { className: 'wpmft-intro' }, h('div', null, h('span', { className: 'wpmft-kicker' }, tr(lang, 'roots')), h('h1', null, title), h('p', null, subtitle)), authorized && boot.stats?.people > 0 && h('div', { className: 'wpmft-stat' }, h('strong', null, new Intl.NumberFormat(lang === 'lv' ? 'lv-LV' : 'en-GB').format(boot.stats.people)), h('span', null, tr(lang, 'people')), h(OakMark, { size: 42 }))),
                !boot ? h('div', { className: 'wpmft-starting' }, startupError ? h('div', null, h(Message, null, startupError), h('button', { type: 'button', className: 'wpmft-button', onClick: () => setReload(v => v + 1) }, tr(lang, 'retry'))) : h(Loader, { label: tr(lang, 'loading') })) :
                    !authorized ? h(Unlock, { boot, lang, onUnlock: unlock, onContact: () => setContact(true), contactEnabled, error: startupError }) : h('div', { className: 'wpmft-explorer' },
                        h('div', { className: 'wpmft-toolbar' }, h(Search, { lang, api, onChoose: chooseRoot }), h('div', { className: 'wpmft-view-switch', role: 'group', 'aria-label': tr(lang, 'selectedView') }, [['tree', 'tree'], ['list', 'people']].map(([key, icon]) => h('button', { type: 'button', key, className: key === view ? 'is-active' : '', 'aria-pressed': key === view, onClick: () => setView(key) }, h(Icon, { name: icon, size: 18 }), tr(lang, key)))),
                            enabled(settings.allowExport) && view === 'tree' && h('div', { className: 'wpmft-export-actions' }, h('button', { type: 'button', className: 'wpmft-button is-small', onClick: () => snapshot(false), disabled: exporting || !tree?.nodes?.length }, h(Icon, { name: 'download', size: 17 }), tr(lang, exporting ? 'exporting' : 'export')), h('button', { type: 'button', className: 'wpmft-icon-button', onClick: () => snapshot(true), disabled: exporting || !tree?.nodes?.length, 'aria-label': tr(lang, 'print'), title: tr(lang, 'print') }, h(Icon, { name: 'print', size: 19 })))),
                        h(Message, null, treeError), treeError && h('button', { type: 'button', className: 'wpmft-button is-small wpmft-retry', onClick: () => setReload(v => v + 1) }, tr(lang, 'retry')), notice && h(Message, { kind: 'success' }, notice),
                        view === 'list' ? h(Directory, { key: lang, lang, api, onPerson: setPersonId, onRoot: chooseRoot }) : tree?.nodes?.length ? h(Tree, { data: tree, lang, busy: treeBusy, onPerson: setPersonId, onRoot: chooseRoot, onHome: () => chooseRoot(initialRoot.current), settings, exportRef: exportLayout }) : treeBusy ? h('div', { className: 'wpmft-starting' }, h(Loader, { label: tr(lang, 'loading') })) : h('section', { className: 'wpmft-empty' }, h(OakMark, { size: 80 }), h('h2', null, tr(lang, 'noPeople')), h('p', null, tr(lang, 'noPeopleHelp'))))),
            h('footer', { className: 'wpmft-footer' }, h('div', { className: 'wpmft-footer-inner' }, h('div', { className: 'wpmft-footer-story' }, h(OakMark, { size: 28 }), h('p', null, tr(lang, 'footer'))), h('div', { className: 'wpmft-footer-contact' }, settings.contactEmail && h('span', null, settings.contactEmail), contactEnabled && h('button', { type: 'button', className: 'wpmft-button is-small', onClick: () => setContact(true) }, h(Icon, { name: 'mail', size: 17 }), tr(lang, 'contact')))),
                enabled(settings.showCredit, false) && h('div', { className: 'wpmft-credit' }, tr(lang, 'credit'), ' ', h('a', { href: 'https://digitalpulse.click/', target: '_blank', rel: 'noopener noreferrer' }, 'DigitalPulse · Raivis Kalnins'))),
            personId && authorized && h(PersonDialog, { key: lang, id: personId, lang, api, onClose: () => setPersonId(null), onPerson: setPersonId, onRoot: chooseRoot }), contact && boot && contactEnabled && h(ContactDialog, { lang, api, captcha: boot.hcaptcha, onClose: () => setContact(false) }));
    }
    function mount() {
        document.querySelectorAll('.wpmft-app').forEach(element => {
            if (element.dataset.mounted) return; element.dataset.mounted = '1';
            if (createRoot) createRoot(element).render(h(App, { element })); else if (render) render(h(App, { element }), element);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once: true }); else mount();
})();
