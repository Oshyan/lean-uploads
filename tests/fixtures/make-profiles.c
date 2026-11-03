/* Generic ICC fixtures generated with LittleCMS 2; no device or operating-system profiles.
 * Build: cc make-profiles.c -o make-profiles $(pkg-config --cflags --libs lcms2)
 * Run: ./make-profiles ../../assets/srgb.icc synthetic-gamma18.icc
 * The fixed timestamp and unset optional profile ID make repeated output deterministic
 * within the same LittleCMS version. Generator and generated assets use the plugin license.
 */
#include <lcms2.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

static int save(cmsHPROFILE profile, const char *path) {
    cmsUInt32Number size = 0;
    if (!cmsSaveProfileToMem(profile, NULL, &size)) return 0;
    unsigned char *data = malloc(size);
    if (!data || !cmsSaveProfileToMem(profile, data, &size)) { free(data); return 0; }
    const unsigned char date[12] = {7,228,0,1,0,1,0,0,0,0,0,0}; /* 2020-01-01 00:00:00 */
    memcpy(data + 24, date, 12);
    memset(data + 84, 0, 16);
    FILE *file = fopen(path, "wb");
    int ok = file && fwrite(data, 1, size, file) == size;
    if (file) fclose(file);
    free(data);
    return ok;
}

int main(int argc, char **argv) {
    if (argc != 3) return 1;
    cmsHPROFILE srgb = cmsCreate_sRGBProfile();
    cmsCIExyY white = {0.3127, 0.3290, 1.0};
    cmsCIExyYTRIPLE primaries = {{0.64,0.33,1.0},{0.30,0.60,1.0},{0.15,0.06,1.0}};
    cmsToneCurve *curve = cmsBuildGamma(NULL, 1.8);
    cmsToneCurve *curves[3] = {curve, curve, curve};
    cmsHPROFILE custom = cmsCreateRGBProfile(&white, &primaries, curves);
    if (!srgb || !curve || !custom) return 2;
    int ok = save(srgb, argv[1]) && save(custom, argv[2]);
    cmsCloseProfile(srgb); cmsCloseProfile(custom); cmsFreeToneCurve(curve);
    return ok ? 0 : 3;
}
