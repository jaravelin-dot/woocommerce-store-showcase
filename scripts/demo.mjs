// Spustí ukázkový obchod lokálně ve WordPress Playground (bez Dockeru a bez PHP).
// Použití: npm run demo   (jiný port: PORT=9500 npm run demo)
import { lokalniBlueprint, spustCli } from './lokalni-blueprint.mjs';

const port = process.env.PORT || '9400';
const blueprint = lokalniBlueprint('blueprint.local.json');

console.log(`Ukázka poběží na http://127.0.0.1:${port} (první start stahuje WordPress a WooCommerce, trvá to několik minut).`);
spustCli('server', [`--blueprint=${blueprint}`, `--port=${port}`, `--site-url=http://127.0.0.1:${port}`]);
