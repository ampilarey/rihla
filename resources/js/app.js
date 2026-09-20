import Alpine from 'alpinejs';
import './interactions';
import { startLeaderPortal } from './leader-offline';
import { startZiyarahOffline } from './ziyarah-offline';

window.Alpine = Alpine;

Alpine.start();

// The Tour Leader Portal's offline queue. It returns immediately on every
// page that is not one of its own, so this costs nothing elsewhere.
startLeaderPortal();

// The Ziyarah Guide's "save it all to this phone" control (§7.2). Same
// shape: it returns immediately unless the control is on the page.
startZiyarahOffline();
