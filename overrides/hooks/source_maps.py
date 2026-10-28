"""MkDocs hook: removes the source maps (*.map) that Material copies with its scripts and styles.

They are only useful to debug the theme and weigh more than the scripts themselves (about 1 MB):
the published site does not need them.
"""

from pathlib import Path


def on_post_build(config, **kwargs):
    """Deletes every *.map file of the built site."""
    for source_map in Path(config["site_dir"]).rglob("*.map"):
        source_map.unlink()
