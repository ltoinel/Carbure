Mon cher Ludo, voici une petite notice des modifications que j'ai effectué dans ton code et le pourquoi du comment.
Tu remarqueras que j'ai souvent tendance à chipoter, ergoter, pinailler et pire encore. Je n'ai pas touché à l'archi (car je la trouve élégante et je me vois pas mettre mes sales pattes dedans) en revanche j'ai essayé de "moderniser" le code pour tirer parti des dernieres évolutions du language. 

- Ajout de composer, c'est le préalable pour gérer des librairies externes, dans ton cas nous avons PhpUnit. Bon vu qu'il n'y a qu'une seule librairie je suis pas sur que ce soit utile, sauf si je trouve woob sur composer.
- J'ai modifié ton autoload pour ne pas tout charger mais uniquement les classes nécessaires. Et pour ça, j'ai du mettre des namespaces sur tes classes.
