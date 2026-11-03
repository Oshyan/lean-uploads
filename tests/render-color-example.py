from pathlib import Path
from PIL import Image, ImageCms, ImageDraw
import io, json, hashlib, sys
if len(sys.argv) != 3: raise SystemExit('Usage: render-color-example.py INPUT_DIRECTORY OUTPUT_DIRECTORY')
root=Path(sys.argv[1]); destination=Path(sys.argv[2]); destination.mkdir(parents=True, exist_ok=True)
source=Image.open(root/'source.png'); source.load()
srgb=ImageCms.createProfile('sRGB')
def managed(im):
    profile=im.info.get('icc_profile')
    if not profile:return im.convert('RGBA')
    return ImageCms.profileToProfile(im.convert('RGBA'),ImageCms.ImageCmsProfile(io.BytesIO(profile)),srgb,outputMode='RGBA')
ref=managed(source)
positions=[(.25,.25),(.75,.25),(.25,.75),(.75,.75)]
def samples(im):return [im.getpixel((int(im.width*x),int(im.height*y))) for x,y in positions]
expected=samples(ref)
rows=[]
for name in ['preserve.png','protected-thumbnail.png','preserve.webp','preserve.avif','normalized.png','native-imagick.png','native-gd.png']:
    if not (root/name).is_file(): continue
    im=Image.open(root/name); im.load(); view=managed(im); got=samples(view)
    color=max(abs(a[c]-b[c]) for a,b in zip(expected,got) for c in range(3))
    alpha=max(abs(a[3]-b[3]) for a,b in zip(expected,got))
    profile=im.info.get('icc_profile',b'')
    native=name.startswith('native-')
    if not native:
        assert profile, name+' lacks ICC'
        assert color <= (3 if name.endswith('avif') else 1),(name,color,got,expected)
        assert alpha<=1,(name,alpha)
        if name.startswith('preserve'):assert profile==source.info['icc_profile'],name+' profile changed'
    # Native controls are observations, not assertions: future WordPress versions may fix them.
    rows.append(dict(file=name,dimensions=im.size,icc_bytes=len(profile),icc_sha256=hashlib.sha256(profile).hexdigest() if profile else None,max_sample_rgb_error=color,max_sample_alpha_error=alpha,managed_samples=got))
report={'independent_decoder':'Pillow / LittleCMS 2','source':'Synthetic RGB patches and transparency; custom gamma 1.8 ICC','expected_srgb_samples':expected,'results':rows,'limitations':'Compares four interior patch samples; not a whole-image visual-difference threshold or broad real-photo corpus.'}
(destination/'saved-output-verification.json').write_text(json.dumps(report,indent=2)+'\n')
# Visual comparison rendered into sRGB for consistent display.
canvas=Image.new('RGB',(1200,520),'#f1eee9'); draw=ImageDraw.Draw(canvas)
for x,label,name in [(0,'Reference (color managed)','source.png'),(400,'Native indexed PNG resize','native-imagick.png'),(800,'Plugin protected thumbnail','protected-thumbnail.png')]:
    im=managed(Image.open(root/name)); im.thumbnail((360,360))
    draw.text((x+20,20),label,fill='#222222')
    checker=Image.new('RGBA',im.size,'white'); cd=ImageDraw.Draw(checker)
    for yy in range(0,im.height,16):
        for xx in range(0,im.width,16):
            if (xx//16+yy//16)%2:cd.rectangle((xx,yy,xx+15,yy+15),fill='#dddddd')
    checker.alpha_composite(im);canvas.paste(checker.convert('RGB'),(x+20,65))
draw.text((20,450),'Synthetic test artwork. All panels rendered to sRGB; checkerboard shows transparency.',fill='#222222')
canvas.save(destination/'color-consistency.png',icc_profile=ImageCms.ImageCmsProfile(srgb).tobytes())
print(json.dumps(report,indent=2))
