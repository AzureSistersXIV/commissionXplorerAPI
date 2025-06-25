<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Metadata and title of the page -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Index | Comex</title>
</head>
<body>
    <header>
        <!-- Header section with the main title -->
        <h1>Comex - Local</h1>
    </header>
    <main>
        <ul>
            <li>
                <!-- Artists section with links to SFW and NSFW artists -->
                <h3>Artists</h3>
                <span><a target="_blank" href="./artists.php">SFW</a> : <a target="_blank" href="./artists.php?isNsfw=true">NSFW</a></span>
            </li>
            <li>
                <!-- Artworks section with a form to search artworks by artist name -->
                <h3>Artworks</h3>
                <span>
                    <form action="./artworks.php" method="POST">
                        <label for="artist">Artist name</label>&nbsp;
                        <input id="artist" name="artist" />
                        <input type="submit" value="submit"/>
                    </form>
                </span>
            </li>
            <li>
                <!-- Thumbnail section with a form to generate a thumbnail by artwork path -->
                <h3>Thumbnail</h3>
                <span>
                    <form action="./thumbnail.php" method="POST">
                        <label for="artwork">Artwork path</label>&nbsp;
                        <input id="artwork" name="artwork"/>
                        <input type="submit" value="submit"/>
                    </form>
                </span>
            </li>
        </ul>
    </main>
    <footer>
        
    </footer>
</body>
</html>