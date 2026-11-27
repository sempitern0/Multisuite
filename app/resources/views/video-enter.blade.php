{{-- Served on the Vídeo gateway's origin (routes/api.php); enter.js stores the session for Jellyfin's web interface. --}}
<!doctype html><html lang="es"><meta charset="utf-8"><meta name="color-scheme" content="dark"><title>Abriendo Vídeo · Multisuite</title>
<body data-session="{{ json_encode($session, JSON_THROW_ON_ERROR) }}"><p>Abriendo Vídeo…</p>
<a href="/_multisuite/portal">Volver a Multisuite</a><script src="/_multisuite/enter.js"></script></body></html>
