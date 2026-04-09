# Telegram OSINT Bot — n8n Setup

## Webhook URL
`https://n8n.la-renting.com/webhook/osint-scan`

## n8n Workflow

### Trigger: Telegram Trigger Node
- Bot: @AdriAssist_bot (oder neuen Bot erstellen)
- Event: Message
- Filter: Nachricht beginnt mit `/osint` oder `/scan`

### Node 1: Parse Message
```javascript
// Eingabe: /osint max.mustermann@gmail.com
// Oder: /scan +4917612345678
// Oder: /osint B-AB 1234
const text = $input.first().json.message.text;
const parts = text.split(' ');
const command = parts[0]; // /osint oder /scan
const query = parts.slice(1).join(' ');

// Auto-detect input type
let email = '', name = '', phone = '', plate = '', serial = '';
if (query.includes('@')) email = query;
else if (query.match(/^\+?\d{8,}/)) phone = query;
else if (query.match(/^[A-Z]{1,3}[\s-][A-Z]{1,3}\s?\d+/i)) plate = query;
else if (query.match(/^\d+\/\d+/)) serial = query;
else name = query;

return [{json: {email, name, phone, plate, serial, chat_id: $input.first().json.message.chat.id}}];
```

### Node 2: HTTP Request — Call OSI API
- Method: POST
- URL: `https://app.fleckfrei.de/api/osint-deep.php`
- Headers: `X-API-Key: ***REDACTED***`
- Body (JSON):
```json
{
  "email": "{{$json.email}}",
  "name": "{{$json.name}}",
  "phone": "{{$json.phone}}",
  "plate": "{{$json.plate}}",
  "serial": "{{$json.serial}}"
}
```
- Timeout: 120s

### Node 3: Format Response
```javascript
const d = $input.first().json.data;
const dos = d.dossier || {};
let msg = `<b>OSI Scan: ${dos.subject || '?'}</b>\n`;
msg += `Risiko: <b>${dos.risk_level || '?'}</b>\n`;
msg += `Module: ${d._meta?.modules_run || '?'} | ${d._meta?.scan_time_seconds || '?'}s\n\n`;

if (dos.findings?.length) {
    msg += '<b>Erkenntnisse:</b>\n';
    dos.findings.slice(0, 8).forEach(f => msg += `- ${f}\n`);
}
if (dos.risk_factors?.length) {
    msg += '\n<b>Risiken:</b>\n';
    dos.risk_factors.forEach(f => msg += `- ${f}\n`);
}
msg += `\nDetails: https://app.fleckfrei.de/admin/scanner.php`;
return [{json: {chat_id: $input.first().json.chat_id, text: msg}}];
```

### Node 4: Telegram — Send Message
- Chat ID: `{{$json.chat_id}}`
- Text: `{{$json.text}}`
- Parse Mode: HTML

## Keywords
- `/osint max.mustermann@gmail.com` — Scan by email
- `/osint Max Mustermann` — Scan by name
- `/osint +4917612345678` — Scan by phone
- `/osint B-AB 1234` — Scan by plate
- `/scan 132/571/00584` — Scan by registration number
