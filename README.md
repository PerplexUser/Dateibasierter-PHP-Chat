# Dateibasierter-PHP-Chat
Ich habe einen kompletten, dateibasierten Chat als einzelne PHP-Datei erstellt inkl. UI, AJAX, Login ohne Passwort, Inaktivitäts-Handling, Online-Liste, Smiley-Umwandlung, automatisches Löschen des Verlaufs, wenn der letzte Nutzer geht.

So setzt du ihn ein:

Lade die Datei herunter und speichere sie als file-based-chat.php in einen Webserver-Ordner.

Stelle sicher, dass PHP aktiviert ist.

Rufe die Datei im Browser auf (z. B. https://deinedomain.de/file-based-chat.php).

Beim ersten Start legt das Script ein Verzeichnis chat_data/ mit den Dateien chat.log und users.json an und setzt sie auf chmod 777.

Hinweise & Features:

Login: nur Nickname → Button klick → eingeloggt (Session-basiert).

Sichtbarkeit ab Eintritt: Neue Nutzer sehen nur Nachrichten ab ihrem Eintrittszeitpunkt.

Automatisches Löschen: Verlässt der letzte Nutzer den Chat, wird chat.log geleert.

Inaktivität: Nach 30 Minuten ohne Heartbeat gilt ein Nutzer als inaktiv (wird aus Online-Liste entfernt; bei Rückkehr sieht er nur Nachrichten ab neuem Eintritt).

Online-Anzeige: Anzahl und Liste der User neben dem Chat.

Smileys → Emoji: :), :-), ;-), :D, :( etc. werden automatisch ersetzt.

Bedienelemente: Eingabefeld + Senden-Button, sowie Ausloggen-Button.
