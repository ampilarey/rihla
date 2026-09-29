import Alpine from 'alpinejs';
import './interactions';
import { startLeaderPortal } from './leader-offline';
import { startZiyarahOffline } from './ziyarah-offline';
import { startStayMap } from './stay-map';

window.Alpine = Alpine;

Alpine.start();

// The Tour Leader Portal's offline queue. It returns immediately on every
// page that is not one of its own, so this costs nothing elsewhere.
startLeaderPortal();

// The Ziyarah Guide's "save it all to this phone" control (§7.2). Same
// shape: it returns immediately unless the control is on the page.
startZiyarahOffline();

// A listing's map (§16.7). Returns immediately unless the page has one,
// and loads Leaflet only once the map is scrolled near.
startStayMap();
