# cookie-form

Plugin WordPress per gestire il download di PDF con form obbligatorio al primo click.

## Obiettivo
Consentire il download di più PDF con CTA dedicate, richiedendo all'utente la compilazione di un form solo la prima volta:
- Nome
- Email
- Azienda

Dopo la prima compilazione valida:
- il download viene sbloccato;
- i click successivi sui bottoni PDF avvengono senza form;
- lo stato viene ricordato tramite cookie + localStorage.

## Funzionalità principali
- Shortcode per bottone download PDF (`[cookie_form_pdf_button]`).
- Shortcode form standalone per Elementor Popup (`[cookie_form_pdf_gate_form]`).
- Modal con form frontend al primo click.
- Invio AJAX sicuro con nonce WordPress.
- Persistenza sblocco utente con:
  - cookie `cookie_form_pdf_gate_unlocked`
  - localStorage `cookie_form_pdf_gate_unlocked`
- Salvataggio lead in WordPress tramite Custom Post Type (`cookie_form_lead`).

## Struttura file
- `cookie-form.php`: bootstrap plugin, shortcode, endpoint AJAX, salvataggio lead.
- `assets/js/cookie-form.js`: logica frontend (gate, modal, submit, unlock, download).
- `assets/css/cookie-form.css`: stili bottone, modal e form.

## Installazione
1. Copia la cartella `cookie-form` in `wp-content/plugins/`.
2. Attiva il plugin da **WordPress Admin > Plugin**.
3. Inserisci lo shortcode dove vuoi mostrare i bottoni CTA.

## Uso dello shortcode
Sintassi base:

```text
[cookie_form_pdf_button pdf_url="https://example.com/file.pdf" label="Scarica PDF"]
```

Parametri disponibili:
- `pdf_url` (obbligatorio): URL del PDF.
- `label` (opzionale): testo del bottone. Default: `Scarica PDF`.
- `class` (opzionale): classi CSS aggiuntive.
- `target` (opzionale): target del link. Default: `_blank`.

Esempio con 2 PDF:

```text
[cookie_form_pdf_button pdf_url="https://tuodominio.it/wp-content/uploads/2026/02/guida-1.pdf" label="Scarica Guida 1"]
[cookie_form_pdf_button pdf_url="https://tuodominio.it/wp-content/uploads/2026/02/guida-2.pdf" label="Scarica Guida 2"]
```

## Uso con Elementor Popup
Per mantenere bottone e layout totalmente in Elementor:

1. Crea un popup Elementor.
2. Nel popup inserisci uno shortcode con:

```text
[cookie_form_pdf_gate_form]
```

3. Sul bottone Elementor:
- Classe CSS: `devmy-pdf-download`
- Link: URL del PDF
- Attributo personalizzato: `data-popup-id|123` (dove `123` è l'ID del popup Elementor)
- Opzionale: `data-target|_blank`

Comportamento:
- utente non sbloccato -> si apre popup Elementor con form;
- submit valido -> lead salvato, popup chiuso, parte download;
- click successivi -> download diretto senza popup.

## Flusso utente
1. L'utente clicca CTA PDF.
2. Se NON sbloccato: si apre modal con form.
3. Compila e invia.
4. Backend valida dati e salva lead.
5. Frontend imposta stato sbloccato (cookie + localStorage).
6. Parte il download del PDF richiesto.
7. Click successivi: download diretto.

## Dati raccolti e storage
Per ogni lead viene creato un post privato `cookie_form_lead` con metadati:
- `name`
- `email`
- `company`
- `source_url`
- `requested_pdf`
- `ip_address`
- `user_agent`

Dove vederli:
- **WordPress Admin > Cookie Form Leads**

## Sicurezza
- Controllo nonce (`check_ajax_referer`) nell'endpoint AJAX.
- Sanitizzazione input (`sanitize_text_field`, `sanitize_email`, `esc_url_raw`).
- Validazione email (`is_email`).
- Form con campi required lato frontend + validazione server-side.

## Comportamento cookie/localStorage
Chiave usata:
- `cookie_form_pdf_gate_unlocked`

Durata:
- 365 giorni.

Note:
- Se utente cancella cookie/localStorage, il form verrà richiesto di nuovo.
- In navigazione anonima/incognito il comportamento dipende dal browser.

## Personalizzazione rapida
- Testi frontend: modificabili nel file `cookie-form.php` (stringhe localizzate in `wp_localize_script`).
- Aspetto modal/bottone: `assets/css/cookie-form.css`.
- Logica gate/download: `assets/js/cookie-form.js`.

## Possibili estensioni future
- Pagina impostazioni plugin in admin.
- Esportazione lead CSV.
- Integrazione CRM (HubSpot, Mailchimp, ecc.).
- Regole diverse per PDF specifici (unlock per singolo file).
- Consenso privacy esplicito con checkbox e link policy.

## Versione
- `1.2.0`

## Compatibilità legacy
Per retrocompatibilità sono ancora supportati:
- shortcode `[devmy_pdf_button]` e `[devmy_pdf_gate_form]`
- action AJAX `devmy_submit_pdf_gate`
- attributi `data-devmy-*` lato frontend

## Compatibilità
- WordPress con jQuery frontend disponibile.
- Testato localmente su installazione WordPress standard.

## Note legali/privacy
Il plugin raccoglie dati personali (nome, email, azienda, IP, user-agent). È consigliato:
- aggiornare privacy policy e cookie policy del sito;
- definire base giuridica del trattamento;
- gestire tempi di conservazione dei lead.
