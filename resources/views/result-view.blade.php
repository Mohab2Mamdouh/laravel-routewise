<x-mcp::app :title="$title">
    <x-slot:head>
        @verbatim
        <style>
            :root {
                --color-background-primary: light-dark(#ffffff, #1f1e1d);
                --color-background-secondary: light-dark(#f7f6f3, #2a2927);
                --color-text-primary: light-dark(#1f1e1d, #f5f4ef);
                --color-text-secondary: light-dark(#6b6a65, #a8a69e);
                --color-border-primary: light-dark(#e6e4dd, #3d3b37);
                --font-sans: system-ui, -apple-system, "Segoe UI", sans-serif;
                --border-radius-md: 10px;
                --accent: #c96442;
            }
            * { box-sizing: border-box; }
            body { margin: 0; padding: 4px; font: 13.5px/1.45 var(--font-sans); color: var(--color-text-primary); background: transparent; }
            h2 { font-size: 14px; margin: 0 0 10px; display: flex; align-items: center; gap: 8px; }
            h2 .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); }
            h3 { font-size: 11.5px; text-transform: uppercase; letter-spacing: .5px; color: var(--color-text-secondary); margin: 16px 0 8px; font-weight: 600; }
            .tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 8px; }
            .tile { background: var(--color-background-secondary); border: 1px solid var(--color-border-primary); border-radius: var(--border-radius-md); padding: 9px 11px; min-width: 0; }
            .tile .k { font-size: 11px; color: var(--color-text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .tile .v { font-size: 16px; font-weight: 600; margin-top: 2px; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
            .tile .v.long { font-size: 13px; font-weight: 400; }
            .table-wrap { overflow-x: auto; border: 1px solid var(--color-border-primary); border-radius: var(--border-radius-md); }
            table { border-collapse: collapse; width: 100%; font-size: 12.5px; }
            th, td { text-align: left; padding: 7px 10px; border-bottom: 1px solid var(--color-border-primary); vertical-align: top; }
            th { font-size: 11px; font-weight: 600; color: var(--color-text-secondary); background: var(--color-background-secondary); white-space: nowrap; }
            tr:last-child td { border-bottom: 0; }
            td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
            .muted { color: var(--color-text-secondary); }
            .swatch { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 6px; vertical-align: -1px; }
            .pill { display: inline-block; padding: 1px 8px; border-radius: 99px; font-size: 11.5px; background: var(--color-background-secondary); border: 1px solid var(--color-border-primary); }
            .pill.yes { color: #2e7d4f; } .pill.no { color: var(--color-text-secondary); }
            .error { border: 1px solid #d9534f; color: #d9534f; border-radius: var(--border-radius-md); padding: 10px 12px; }
            pre { white-space: pre-wrap; overflow-wrap: anywhere; background: var(--color-background-secondary); border: 1px solid var(--color-border-primary); border-radius: var(--border-radius-md); padding: 10px 12px; margin: 0; font-size: 12px; max-height: 420px; overflow: auto; }
            a { color: var(--accent); }
        </style>
        <script type="module">
            const root = () => document.getElementById('app');
            const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
            const label = (k) => String(k).replace(/_/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').replace(/^./, (c) => c.toUpperCase());
            const isScalar = (v) => v === null || typeof v !== 'object';
            const isRows = (v) => Array.isArray(v) && v.length > 0 && v.every((r) => r && typeof r === 'object' && !Array.isArray(r));

            function cell(v) {
                if (v === null || v === undefined || v === '') return '<span class="muted">–</span>';
                if (typeof v === 'boolean') return `<span class="pill ${v ? 'yes' : 'no'}">${v ? 'yes' : 'no'}</span>`;
                if (typeof v === 'number') return esc(v.toLocaleString(undefined, { maximumFractionDigits: 2 }));
                if (typeof v === 'string' && /^#[0-9a-f]{6}$/i.test(v)) return `<span class="swatch" style="background:${v}"></span>${esc(v)}`;
                if (typeof v === 'string' && /^https?:\/\//.test(v)) return `<a href="#" data-url="${esc(v)}">${esc(v.replace(/^https?:\/\//, '').slice(0, 60))}</a>`;
                if (Array.isArray(v)) return v.every(isScalar) ? esc(v.join(', ')) : `<span class="muted">${v.length} items</span>`;
                if (typeof v === 'object') return esc(Object.entries(v).filter(([, x]) => isScalar(x)).map(([k, x]) => `${label(k)}: ${x ?? '–'}`).join(' · '));
                return esc(v);
            }

            function tiles(obj) {
                const html = Object.entries(obj).map(([k, v]) => {
                    const long = typeof v === 'string' && v.length > 24;
                    return `<div class="tile"><div class="k" title="${esc(label(k))}">${esc(label(k))}</div><div class="v${long ? ' long' : ''}">${cell(v)}</div></div>`;
                }).join('');
                return `<div class="tiles">${html}</div>`;
            }

            function table(rows) {
                const cols = [...new Set(rows.flatMap(Object.keys))].slice(0, 9);
                const numeric = cols.filter((c) => rows.every((r) => r[c] === null || r[c] === undefined || typeof r[c] === 'number'));
                const head = cols.map((c) => `<th>${esc(label(c))}</th>`).join('');
                const body = rows.map((r) => `<tr>${cols.map((c) => `<td class="${numeric.includes(c) ? 'num' : ''}">${cell(r[c])}</td>`).join('')}</tr>`).join('');
                return `<div class="table-wrap"><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
            }

            // Scalars become tiles, lists of records become tables, nested objects become sections.
            function render(value, depth = 0) {
                if (isRows(value)) return table(value);
                if (Array.isArray(value)) return value.length ? `<pre>${esc(value.join('\n'))}</pre>` : '<p class="muted">Nothing here.</p>';
                if (isScalar(value)) return `<div class="tiles"><div class="tile"><div class="v">${cell(value)}</div></div></div>`;
                const scalars = Object.fromEntries(Object.entries(value).filter(([, v]) => isScalar(v) || (Array.isArray(v) && v.length > 0 && v.every(isScalar))));
                let html = Object.keys(scalars).length ? tiles(scalars) : '';
                for (const [k, v] of Object.entries(value)) {
                    if (k in scalars) continue;
                    if (Array.isArray(v) && v.length === 0) continue;
                    html += `<h3>${esc(label(k))}</h3>` + (depth > 2 ? `<pre>${esc(JSON.stringify(v, null, 2))}</pre>` : render(v, depth + 1));
                }
                return html || '<p class="muted">Nothing here.</p>';
            }

            function show(result, app) {
                const text = (result?.content || []).filter((c) => c.type === 'text').map((c) => c.text).join('\n');
                const name = app.getHostContext()?.toolInfo?.tool?.name;
                const title = name ? `<h2><span class="dot"></span>${esc(label(name.replace(/_/g, ' ')))}</h2>` : '';
                if (result?.isError) { root().innerHTML = title + `<div class="error">${esc(text)}</div>`; return; }
                let data;
                try { data = JSON.parse(text); } catch { root().innerHTML = title + `<pre>${esc(text)}</pre>`; return; }
                root().innerHTML = title + render(data);
            }

            createMcpApp(async (app) => {
                app.autoResize();
                app.onToolResult((result) => show(result, app));
                document.addEventListener('click', (e) => {
                    const a = e.target.closest('a[data-url]');
                    if (a) { e.preventDefault(); app.openLink(a.dataset.url); }
                });
            });
        </script>
        @endverbatim
    </x-slot:head>

    <div id="app"><p class="muted">Loading…</p></div>
</x-mcp::app>
