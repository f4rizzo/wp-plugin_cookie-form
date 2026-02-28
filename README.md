# Cookie form download gate

Plugin WordPress per bloccare il primo download PDF con un form (Nome, Email, Azienda) e sbloccare i download successivi tramite cookie + localStorage.

## Stato documentazione
Documentazione aggiornata e allineata alla versione plugin `1.3.1`.

## Panoramica rapida
- Primo click su CTA PDF: form obbligatorio.
- Submit valido: lead salvato e download avviato.
- Click successivi: download diretto (utente sbloccato per 365 giorni).
- Tracciamento file: PDF principale (unlock) + altri PDF scaricati dallo stesso utente.
- Lead archiviati come post privati `cookie_form_lead` + export CSV da admin.

## Quando usare "Nativo" vs "Elementor"
- `Nativo`: vuoi inserire i bottoni PDF direttamente nel contenuto WordPress con shortcode plugin.
- `Elementor`: vuoi controllare layout bottone e popup interamente in Elementor.

## Tutorial A: uso nativo (shortcode plugin)
Questo è il setup più veloce: usi lo shortcode bottone e il plugin gestisce modal + form automaticamente.

1. Installa/attiva il plugin in `wp-content/plugins/cookie-form`.
2. Inserisci uno o più shortcode nelle pagine/articoli.

```text
[cookie_form_pdf_button pdf_url="https://example.com/file.pdf" label="Scarica PDF"]
```

Esempio con più PDF:

```text
[cookie_form_pdf_button pdf_url="https://tuodominio.it/wp-content/uploads/2026/02/guida-1.pdf" label="Scarica Guida 1"]
[cookie_form_pdf_button pdf_url="https://tuodominio.it/wp-content/uploads/2026/02/guida-2.pdf" label="Scarica Guida 2"]
```

Parametri shortcode:
- `pdf_url` (obbligatorio): URL PDF.
- `label` (opzionale): testo bottone, default `Scarica PDF`.
- `class` (opzionale): classi CSS aggiuntive.
- `target` (opzionale): default `_blank`.

Flusso nativo:
1. Click utente su bottone shortcode.
2. Se non sbloccato: apertura modal interno plugin.
3. Submit form via AJAX.
4. Salvataggio lead + sblocco utente.
5. Download immediato del PDF richiesto.

## Tutorial B: uso con Elementor (popup + form shortcode)
Usa questa modalità se vuoi bottone e popup costruiti in Elementor.

1. Crea un popup in Elementor.
2. Nel popup inserisci uno shortcode widget con:

```text
[cookie_form_pdf_gate_form]
```

3. Configura il bottone Elementor che deve aprire il gate:
- Classe CSS: `devmy-pdf-download`
- Link: URL PDF finale
- Attributo custom: `data-popup-id|123` (ID popup Elementor)
- Opzionale: `data-target|_blank`

Flusso Elementor:
1. Click su bottone Elementor.
2. Se non sbloccato: apertura popup Elementor indicato da `data-popup-id`.
3. Submit form nel popup.
4. Lead salvato, popup chiuso, download avviato.
5. Click successivi: download diretto senza popup.

## Dati raccolti e dove trovarli
Per ogni submit viene creato un post privato `cookie_form_lead` con:
- `name`
- `email`
- `company`
- `data_storage_consent` (consenso esplicito all'archiviazione dati)
- `data_storage_consent_at` (data/ora del consenso)
- `source_url`
- `requested_pdf` (PDF principale che sblocca il form)
- `downloaded_pdfs` (lista PDF scaricati dal lead)
- `download_events` (storico eventi download con data/ora)
- `ip_address`
- `user_agent`

Backoffice:
- menu admin lead: **eBook Leads**
- export CSV: pulsante **Esporta CSV** nella lista lead
- export GDPR (Strumenti > Esporta dati personali): include anche stato e data del consenso

## Sicurezza e persistenza
- Nonce AJAX: `check_ajax_referer`.
- Sanitizzazione e validazione server-side dei campi.
- Stato sblocco salvato in:
  - cookie `cookie_form_pdf_gate_unlocked`
  - localStorage `cookie_form_pdf_gate_unlocked`
- Durata sblocco: 365 giorni.

## Compatibilità legacy
Restano supportati:
- shortcode `[devmy_pdf_button]` e `[devmy_pdf_gate_form]`
- action AJAX `devmy_submit_pdf_gate`
- action AJAX `devmy_track_pdf_download`
- attributi frontend `data-devmy-*`

## File principali
- `cookie-form.php`: bootstrap plugin, CPT lead, shortcode, AJAX, CSV.
- `assets/js/cookie-form.js`: logica gate (modal/popup, submit, unlock, download).
- `assets/css/cookie-form.css`: stili bottone/modal/form.

## Note privacy
Il plugin tratta dati personali (nome, email, azienda, IP, user-agent). Va aggiornato il set documentale privacy/cookie del sito in base al contesto legale del progetto.
