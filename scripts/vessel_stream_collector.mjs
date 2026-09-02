const [south, west, north, east, duration = '2.5'] = process.argv.slice(2).map(Number);
const key = process.env.AISSTREAM_API_KEY || '';
const url = process.env.AISSTREAM_URL || 'wss://stream.aisstream.io/v0/stream';
const vessels = new Map();
let confirmed = false;
let finished = false;

const finish = (error) => {
  if (finished) return;
  finished = true;
  clearTimeout(timer);
  try { socket.close(); } catch {}
  if (error) {
    process.stderr.write(error);
    process.exitCode = 1;
  } else {
    process.stdout.write(JSON.stringify([...vessels.values()]));
  }
};

const socket = new WebSocket(url);
const timer = setTimeout(() => finish(confirmed ? null : 'Vessel stream did not confirm the subscription.'), Math.max(500, duration * 1000));

socket.addEventListener('open', () => socket.send(JSON.stringify({
  APIKey: key,
  BoundingBoxes: [[ [south, west], [north, east] ]],
  FilterMessageTypes: ['PositionReport', 'StandardClassBPositionReport', 'ExtendedClassBPositionReport', 'LongRangeAisBroadcastMessage', 'ShipStaticData', 'StaticDataReport'],
})));

socket.addEventListener('message', async (event) => {
  if (finished) return;
  try {
    const text = typeof event.data === 'string' ? event.data : Buffer.from(await event.data.arrayBuffer()).toString('utf8');
    const payload = JSON.parse(text);
    if (payload.error) return finish('The vessel live-data subscription was rejected.');
    if (payload.MessageType === 'SubscriptionConfirmation') { confirmed = true; return; }
    const type = payload.MessageType || '';
    const metadata = payload.MetaData || payload.Metadata || {};
    const body = payload.Message?.[type] || {};
    const mmsi = String(metadata.MMSI ?? body.UserID ?? '');
    if (!mmsi) return;
    const current = vessels.get(mmsi) || { mmsi };
    const lat = metadata.Latitude ?? metadata.latitude ?? body.Latitude;
    const lon = metadata.Longitude ?? metadata.longitude ?? body.Longitude;
    const next = { ...current, mmsi, name: String(metadata.ShipName ?? body.Name ?? current.name ?? '').trim(), updated_at: new Date().toISOString() };
    if (Number.isFinite(Number(lat)) && Number.isFinite(Number(lon))) { next.lat = Number(lat); next.lon = Number(lon); }
    for (const [source, target] of [['Sog','speed'],['Cog','course'],['TrueHeading','heading'],['NavigationalStatus','navigation_status'],['Type','ship_type']]) {
      if (Number.isFinite(Number(body[source]))) next[target] = Number(body[source]);
    }
    if (body.Destination != null) next.destination = String(body.Destination).trim();
    if (body.CallSign != null) next.callsign = String(body.CallSign).trim();
    vessels.set(mmsi, next);
  } catch (error) {
    finish(error instanceof Error ? error.message : 'Invalid vessel message.');
  }
});

socket.addEventListener('error', () => { if (!finished) finish('The vessel live-data socket failed.'); });
