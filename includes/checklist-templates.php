<?php
/**
 * Pre-defined checklist templates — customers can 1-click import these.
 * Source of truth: this file. Templates are just arrays, no DB table needed.
 */

function getChecklistTemplates(): array {
    return [
        'airbnb_standard' => [
            'label' => 'Airbnb / Ferienwohnung Standard',
            'description' => 'Komplette Reinigung nach Gast-Checkout — bereit für nächsten Gast',
            'icon' => '🏠',
            'items' => [
                // Küche
                ['room' => 'Küche', 'priority' => 'high', 'title' => 'Arbeitsflächen desinfizieren', 'description' => 'Alle Arbeitsflächen mit Desinfektionsmittel abwischen. Auch Griffe von Schränken.'],
                ['room' => 'Küche', 'priority' => 'normal', 'title' => 'Kühlschrank leeren und auswischen', 'description' => 'Gäste-Reste entsorgen, alle Fächer mit feuchtem Tuch auswischen.'],
                ['room' => 'Küche', 'priority' => 'normal', 'title' => 'Spüle + Wasserhahn entkalken', 'description' => 'Spüle mit Spülmittel reinigen, Wasserhahn glänzend polieren.'],
                ['room' => 'Küche', 'priority' => 'high', 'title' => 'Geschirr spülen + einräumen', 'description' => 'Falls benutztes Geschirr da ist: spülen (Maschine oder Hand) und in die Schränke zurück.'],
                ['room' => 'Küche', 'priority' => 'normal', 'title' => 'Müll leeren', 'description' => 'Alle Mülleimer leeren, neue Tüten einsetzen.'],
                ['room' => 'Küche', 'priority' => 'high', 'title' => 'Boden wischen', 'description' => 'Erst fegen/saugen, dann feucht wischen.'],
                // Badezimmer
                ['room' => 'Badezimmer', 'priority' => 'critical', 'title' => 'WC gründlich reinigen', 'description' => 'WC-Schüssel innen und außen mit WC-Reiniger. Sitz beide Seiten abwischen. Brille auch.'],
                ['room' => 'Badezimmer', 'priority' => 'critical', 'title' => 'Dusche / Badewanne entkalken', 'description' => 'Kalk an Armaturen, Duschwand, Fliesen entfernen. Abfluss kontrollieren.'],
                ['room' => 'Badezimmer', 'priority' => 'high', 'title' => 'Spiegel streifenfrei putzen', 'description' => 'Mit Glasreiniger + Mikrofaser. Keine Streifen.'],
                ['room' => 'Badezimmer', 'priority' => 'high', 'title' => 'Waschbecken + Armaturen', 'description' => 'Waschbecken, Hahn, Ablage — alles sauber und trocken polieren.'],
                ['room' => 'Badezimmer', 'priority' => 'normal', 'title' => 'Handtücher erneuern', 'description' => 'Gebrauchte Handtücher in die Wäsche. Frische Handtücher hinlegen (Badetuch, Handtuch, Gästehandtuch pro Person).'],
                ['room' => 'Badezimmer', 'priority' => 'normal', 'title' => 'Boden wischen', 'description' => 'Mit Desinfektionsreiniger.'],
                // Schlafzimmer
                ['room' => 'Schlafzimmer', 'priority' => 'critical', 'title' => 'Bett neu beziehen', 'description' => 'Gebrauchte Wäsche abziehen. Frische Bettwäsche aufziehen (siehe Wäscheschrank).'],
                ['room' => 'Schlafzimmer', 'priority' => 'normal', 'title' => 'Staub wischen', 'description' => 'Nachttische, Fensterbank, Lampen, Kommode. Alles abstauben.'],
                ['room' => 'Schlafzimmer', 'priority' => 'normal', 'title' => 'Boden saugen + wischen'],
                // Wohnzimmer
                ['room' => 'Wohnzimmer', 'priority' => 'normal', 'title' => 'Couch aufschütteln + Kissen arrangieren', 'description' => 'Krümel zwischen Kissen entfernen, Kissen aufschütteln.'],
                ['room' => 'Wohnzimmer', 'priority' => 'normal', 'title' => 'TV + Fernbedienung entstauben', 'description' => 'TV-Bildschirm mit Mikrofaser. Fernbedienung desinfizieren.'],
                ['room' => 'Wohnzimmer', 'priority' => 'normal', 'title' => 'Boden saugen + wischen'],
                // Check
                ['room' => 'Allgemein', 'priority' => 'high', 'title' => 'Mülltrennung prüfen', 'description' => 'Alle Mülleimer in allen Räumen geleert?'],
                ['room' => 'Allgemein', 'priority' => 'critical', 'title' => 'Schlüsselbox / Türcode prüfen', 'description' => 'Türen abgeschlossen? Schlüssel am richtigen Platz?'],
            ],
        ],

        'kueche_grund' => [
            'label' => 'Küche Grundreinigung',
            'description' => 'Tiefenreinigung der Küche — auch Backofen und Abzugshaube',
            'icon' => '🍳',
            'items' => [
                ['room' => 'Küche', 'priority' => 'critical', 'title' => 'Backofen innen reinigen', 'description' => 'Backofen mit Backofenreiniger einsprühen, 15 Min einwirken lassen. Gitter entnehmen und separat reinigen. Achtung: Glasscheibe nicht mit kratzenden Schwämmen.'],
                ['room' => 'Küche', 'priority' => 'high', 'title' => 'Abzugshaube entfetten', 'description' => 'Metallfilter entnehmen und in heißem Spülwasser einweichen. Haube außen mit Entfetter.'],
                ['room' => 'Küche', 'priority' => 'high', 'title' => 'Kühlschrank komplett auswischen', 'description' => 'Alle Fächer entnehmen, mit Essig-Wasser auswischen. Dichtungen nicht vergessen.'],
                ['room' => 'Küche', 'priority' => 'high', 'title' => 'Mikrowelle innen', 'description' => 'Feuchtes Tuch mit Zitrone drin erhitzen, dann auswischen. Kein Chemiereiniger.'],
                ['room' => 'Küche', 'priority' => 'normal', 'title' => 'Schränke außen abwischen', 'description' => 'Alle Schrankfronten mit feuchtem Tuch.'],
                ['room' => 'Küche', 'priority' => 'normal', 'title' => 'Herdplatten + Ceran', 'description' => 'Ceran-Reiniger verwenden. Keine scharfen Schwämme.'],
                ['room' => 'Küche', 'priority' => 'normal', 'title' => 'Spülmaschine reinigen', 'description' => 'Filter entnehmen und reinigen. Maschinenreiniger-Tab einmal laufen lassen.'],
                ['room' => 'Küche', 'priority' => 'normal', 'title' => 'Boden komplett reinigen'],
            ],
        ],

        'bad_grund' => [
            'label' => 'Bad Grundreinigung',
            'description' => 'Kalk, Schimmel, Fugen — alles strahlend sauber',
            'icon' => '🛁',
            'items' => [
                ['room' => 'Badezimmer', 'priority' => 'critical', 'title' => 'Fliesen-Fugen auf Schimmel prüfen', 'description' => 'Bei Schimmel sofort melden und mit Schimmelentferner behandeln.'],
                ['room' => 'Badezimmer', 'priority' => 'critical', 'title' => 'Duschwand + Glasscheibe entkalken', 'description' => 'Anticalc + Abzieher. Bis alles streifenfrei glänzt.'],
                ['room' => 'Badezimmer', 'priority' => 'critical', 'title' => 'Armaturen polieren', 'description' => 'Wasserhahn, Duschkopf, Duschhalter — alles kalkfrei polieren.'],
                ['room' => 'Badezimmer', 'priority' => 'high', 'title' => 'WC gründlich (innen, außen, Sitz, Sockel)', 'description' => 'Auch hinter dem WC und am Boden drumherum.'],
                ['room' => 'Badezimmer', 'priority' => 'high', 'title' => 'Abflüsse reinigen', 'description' => 'Haar und Dreck aus Dusche + Waschbecken entfernen. Bei Verstopfung melden.'],
                ['room' => 'Badezimmer', 'priority' => 'normal', 'title' => 'Spiegel + Glas streifenfrei'],
                ['room' => 'Badezimmer', 'priority' => 'normal', 'title' => 'Waschbeckenschrank auswischen'],
                ['room' => 'Badezimmer', 'priority' => 'normal', 'title' => 'Fliesenboden desinfizieren'],
            ],
        ],

        'buero' => [
            'label' => 'Büro-Reinigung (B2B)',
            'description' => 'Schreibtische, Bildschirme, Böden, Küchenbereich',
            'icon' => '💼',
            'items' => [
                ['room' => 'Arbeitsbereich', 'priority' => 'normal', 'title' => 'Schreibtische abwischen', 'description' => 'Staub entfernen. Vorsicht mit Papieren auf den Tischen — nicht verschieben!'],
                ['room' => 'Arbeitsbereich', 'priority' => 'normal', 'title' => 'Bildschirme + Tastaturen', 'description' => 'Bildschirme mit Mikrofaser (TROCKEN), Tastaturen mit Desinfektionstüchern.'],
                ['room' => 'Arbeitsbereich', 'priority' => 'normal', 'title' => 'Papierkörbe leeren'],
                ['room' => 'Arbeitsbereich', 'priority' => 'normal', 'title' => 'Böden saugen + wischen'],
                ['room' => 'Teeküche', 'priority' => 'high', 'title' => 'Kaffeemaschine reinigen', 'description' => 'Trester entsorgen, Auffangwanne ausleeren, Maschine außen wischen.'],
                ['room' => 'Teeküche', 'priority' => 'high', 'title' => 'Spüle + Geschirr', 'description' => 'Gespültes Geschirr einräumen, Spüle sauber hinterlassen.'],
                ['room' => 'Teeküche', 'priority' => 'normal', 'title' => 'Tische + Stühle'],
                ['room' => 'WC', 'priority' => 'critical', 'title' => 'WCs gründlich reinigen', 'description' => 'Alle Toiletten, Waschbecken, Spiegel, Boden. Papier nachfüllen.'],
                ['room' => 'WC', 'priority' => 'high', 'title' => 'Handtuch / Papier nachfüllen'],
                ['room' => 'WC', 'priority' => 'normal', 'title' => 'Seife nachfüllen'],
            ],
        ],

        'umzug' => [
            'label' => 'Umzugs-Endreinigung',
            'description' => 'Besenreine Übergabe — alles muss blitzblank sein',
            'icon' => '📦',
            'items' => [
                ['room' => 'Allgemein', 'priority' => 'critical', 'title' => 'Alle Räume besenrein', 'description' => 'Kein Müll, kein Staub, keine Krümel.'],
                ['room' => 'Küche', 'priority' => 'critical', 'title' => 'Backofen innen', 'description' => 'Wichtigster Punkt bei Übergabe. Muss fettfrei sein.'],
                ['room' => 'Küche', 'priority' => 'critical', 'title' => 'Alle Schränke innen ausgewischt'],
                ['room' => 'Küche', 'priority' => 'critical', 'title' => 'Kühlschrank innen + Türdichtung'],
                ['room' => 'Badezimmer', 'priority' => 'critical', 'title' => 'Dusche, WC, Waschbecken — alles entkalkt'],
                ['room' => 'Badezimmer', 'priority' => 'critical', 'title' => 'Fugen weiß', 'description' => 'Bei alten Fugen mit Schimmel — melden, nicht selbst reparieren.'],
                ['room' => 'Allgemein', 'priority' => 'high', 'title' => 'Fenster innen reinigen', 'description' => 'Alle Fenster + Rahmen + Fensterbänke.'],
                ['room' => 'Allgemein', 'priority' => 'high', 'title' => 'Türen + Zargen abwischen', 'description' => 'Auch Türgriffe und Türblätter innen/außen.'],
                ['room' => 'Allgemein', 'priority' => 'high', 'title' => 'Heizkörper entstauben'],
                ['room' => 'Allgemein', 'priority' => 'high', 'title' => 'Böden: erst saugen, dann gründlich wischen'],
                ['room' => 'Allgemein', 'priority' => 'critical', 'title' => 'Lichtschalter + Steckdosen abwischen'],
            ],
        ],
    ];
}
