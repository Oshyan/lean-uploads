# Synthetic color fixtures

`synthetic-gamma18.icc` was generated with LittleCMS 2 using D65, sRGB RGB primaries, and gamma 1.8. It deliberately differs from sRGB despite the generic profile description. It contains no photographic, device, or client data. It is provided under the repository license.

`make-profiles.c` reproduces this profile and a generic sRGB profile. On a system with a C compiler and LittleCMS development headers:

```sh
cc make-profiles.c $(pkg-config --cflags --libs lcms2) -o make-profiles
./make-profiles srgb.icc synthetic-gamma18.icc
```

Creation timestamps can differ; tests compare color-transform tags rather than descriptions or timestamps when identifying sRGB.
