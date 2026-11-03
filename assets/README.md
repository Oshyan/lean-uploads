# Standard color profile

`srgb.icc` is a generic standard sRGB characterization generated using LittleCMS 2,
not a profile copied from an operating system, display, camera or user image.
The reproducible generator is `tests/fixtures/make-profiles.c`; it also produces a
synthetic gamma-1.8 RGB profile for regression tests. The generator and generated
assets are distributed under this plugin's GPL-2.0-or-later license.

The timestamp is fixed and the optional ICC profile ID is unset. Color transforms
depend on the profile's matrix and transfer curves, not the profile description.
