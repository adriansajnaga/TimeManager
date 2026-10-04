body { font-family: dejavusanscondensed; font-size: 9pt; color: #000; }
h1 { font-size: 15pt; font-weight: bold; margin: 0; }
p { margin: 0; }
.small { font-size: 7pt; }
.tiny { font-size: 6pt; }
.bold { font-weight: bold; }
.center { text-align: center; }
.right { text-align: right; }
.muted { color: #555; }
.section-title { font-weight: bold; margin: 0 0 2mm 0; }
.spacer { height: 6mm; }

table.layout { width: 100%; border-collapse: collapse; }
table.layout td { vertical-align: top; padding: 0; }

table.grid { border-collapse: collapse; width: 100%; }
table.grid th, table.grid td { border: 0.2mm solid #000; padding: 0.8mm 1.2mm; vertical-align: middle; }
table.grid th { font-weight: normal; text-align: center; }
table.grid td.num { text-align: center; }
table.grid tr.strong td { font-weight: bold; }

.text-box { height: 32mm; vertical-align: top; }

/* Faktura (układ PM) */
.inv { font-size: 8.5pt; }
.inv h1 { font-size: 14pt; letter-spacing: 0.3pt; }
.inv .en { font-size: 6.5pt; color: #555; font-weight: normal; }
.inv .label { color: #555; }
.inv .annotation { font-size: 9pt; font-weight: bold; margin-top: 1.5mm; }
.inv .draft { color: #b91c1c; font-weight: bold; font-size: 9pt; margin-bottom: 2mm; }
.inv .party { border: 0.2mm solid #999; padding: 2mm 2.5mm; vertical-align: top; }
.inv table.data { border-collapse: collapse; width: 100%; }
.inv table.data th, .inv table.data td { border: 0.2mm solid #999; padding: 1mm 1.5mm; vertical-align: top; }
.inv table.data th { background-color: #f1f1f1; font-size: 7pt; font-weight: bold; text-align: center; vertical-align: middle; }
.inv table.data td.num { text-align: center; white-space: nowrap; }
/* Pozycje faktury nieco mniejszą czcionką. */
.inv table.items td { font-size: 7.5pt; }
.inv table.data td.ctr { text-align: center; }
.inv table.data tr.total td { font-weight: bold; background-color: #f1f1f1; }
/* Pusta komórka z lewej strony zestawienia VAT — bez ramki i tła. */
.inv table.data td.blank, .inv table.data tr.total td.blank { border: none; background-color: transparent; }
.inv .due { font-size: 11pt; font-weight: bold; }
.inv .section { font-weight: bold; margin: 3mm 0 1mm 0; }
.inv .note { font-size: 7.5pt; color: #333; margin-top: 1.5mm; }
