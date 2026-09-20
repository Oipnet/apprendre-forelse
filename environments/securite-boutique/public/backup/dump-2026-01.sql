-- Sauvegarde partielle de la base Lacombe — janvier 2026
-- (Extrait FICTIF, laissé par erreur dans public/backup/ par l'hébergeur.)
INSERT INTO client (email, nom, mot_de_passe, roles) VALUES
('marc@brasserie-lacombe.fr', 'Marc Lacombe', '8b1a9953c4611296a827abf8c47804d7', '["ROLE_ADMIN"]'),
('claire.fournier@example.com', 'Claire Fournier', '5f4dcc3b5aa765d61d8327deb882cf99', '[]'),
('paul.martin@example.com', 'Paul Martin', '5f4dcc3b5aa765d61d8327deb882cf99', '[]');
-- 5f4dcc3b5aa765d61d8327deb882cf99 = md5("password") — un attaquant le reconnaît d'un coup d'œil.
