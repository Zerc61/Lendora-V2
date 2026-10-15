<script setup lang="ts">
import type * as Three from 'three'
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { gsap } from 'gsap'
import { clamp } from '../utils/motion'

const props = defineProps<{ active?: boolean }>()
const emit = defineEmits<{ ready: []; error: [error: unknown] }>()

const stage = ref<HTMLDivElement | null>(null)
const loading = ref(true)
const failed = ref(false)
const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)')

let renderer: Three.WebGLRenderer | undefined
let scene: Three.Scene | undefined
let environmentTarget: Three.WebGLRenderTarget | undefined
let threeRuntime: typeof import('three') | undefined
let resizeObserver: ResizeObserver | undefined
let returnTween: gsap.core.Tween | undefined
let mode: 'idle' | 'dragging' | 'returning' = 'idle'
let turntable: Three.Group | undefined
let viewerUnavailable = false
let cameraPitch = 0
let cameraRestPitch = 0
let disposed = false
let downX = 0
let downY = 0
let drawWidth = 0
let drawHeight = 0
let failedTimer = 0
let releaseTimer = 0
let removePointerHandlers: (() => void) | undefined
let removeMotionPreference: (() => void) | undefined
let started = false

async function createCameraScene(host: HTMLElement) {
  const [THREE, { RoomEnvironment }, { RoundedBoxGeometry }] = await Promise.all([
    import('three'),
    import('three/addons/environments/RoomEnvironment.js'),
    import('three/addons/geometries/RoundedBoxGeometry.js'),
  ])
  if (disposed || viewerUnavailable) return
  threeRuntime = THREE

  const width = Math.max(1, host.clientWidth)
  const height = Math.max(1, host.clientHeight)
  const webgl = new THREE.WebGLRenderer({
    antialias: true,
    alpha: true,
    powerPreference: 'high-performance',
  })
  renderer = webgl
  webgl.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5))
  webgl.setSize(width, height)
  webgl.shadowMap.enabled = true
  webgl.shadowMap.type = THREE.PCFSoftShadowMap
  webgl.toneMapping = THREE.ACESFilmicToneMapping
  webgl.toneMappingExposure = 1.1
  webgl.outputColorSpace = THREE.SRGBColorSpace
  webgl.setClearColor(0x000000, 0)
  webgl.domElement.setAttribute('aria-hidden', 'true')
  webgl.domElement.setAttribute('draggable', 'false')
  webgl.domElement.style.touchAction = 'none'
  const viewerCanvas = webgl.domElement
  host.appendChild(viewerCanvas)
  viewerCanvas.tabIndex = 0
  viewerCanvas.setAttribute(
    'aria-label',
    'Model kamera vintage 3D. Seret untuk memutar, atau gunakan tombol panah untuk mengubah sudut.',
  )

  const world = new THREE.Scene()
  scene = world
  world.background = null
  world.fog = null

  const pmrem = new THREE.PMREMGenerator(webgl)
  const room = new RoomEnvironment(webgl)
  environmentTarget = pmrem.fromScene(room, 0.04)
  room.dispose()
  world.environment = environmentTarget.texture
  pmrem.dispose()

  const camera = new THREE.PerspectiveCamera(40, width / height, 0.1, 100)
  camera.position.set(4.4, 2.7, 6.6)
  const cameraTarget = new THREE.Vector3(0, 1.05, 0)
  const cameraSpherical = new THREE.Spherical().setFromVector3(
    camera.position.clone().sub(cameraTarget),
  )
  cameraPitch = cameraRestPitch = cameraSpherical.phi
  camera.lookAt(cameraTarget)
  const updateCameraPitch = () => {
    cameraSpherical.phi = cameraPitch
    camera.position.setFromSpherical(cameraSpherical).add(cameraTarget)
    camera.lookAt(cameraTarget)
  }
  let renderOnce = () => {}

  const maxAniso = webgl.capabilities.getMaxAnisotropy()
  const makeTexture = (
    draw: (context: CanvasRenderingContext2D, canvas: HTMLCanvasElement) => void,
    width: number,
    height = width,
    colorTexture = true,
  ) => {
    const image = document.createElement('canvas')
    image.width = width
    image.height = height
    const context = image.getContext('2d')
    if (!context) throw new Error('Canvas 2D is unavailable for camera textures.')
    draw(context, image)
    const texture = new THREE.CanvasTexture(image)
    if (colorTexture) texture.colorSpace = THREE.SRGBColorSpace
    texture.anisotropy = maxAniso
    return texture
  }

  const leatherBump = makeTexture(
    (g, image) => {
      g.fillStyle = '#808080'
      g.fillRect(0, 0, image.width, image.height)
      for (let i = 0; i < 14000; i++) {
        const shade = 110 + Math.floor(Math.random() * 90)
        g.fillStyle = `rgba(${shade},${shade},${shade},${0.2 + Math.random() * 0.4})`
        g.beginPath()
        g.arc(
          Math.random() * image.width,
          Math.random() * image.height,
          Math.random() * 2.2 + 0.4,
          0,
          Math.PI * 2,
        )
        g.fill()
      }
      g.strokeStyle = 'rgba(60,60,60,.25)'
      for (let i = 0; i < 160; i++) {
        g.lineWidth = 0.5 + Math.random()
        g.beginPath()
        let x = Math.random() * image.width
        let y = Math.random() * image.height
        g.moveTo(x, y)
        for (let k = 0; k < 3; k++) {
          x += Math.random() * 40 - 20
          y += Math.random() * 40 - 20
          g.lineTo(x, y)
        }
        g.stroke()
      }
    },
    512,
    512,
    false,
  )
  leatherBump.wrapS = leatherBump.wrapT = THREE.RepeatWrapping
  leatherBump.repeat.set(2.5, 1.5)

  const makeKnurl = (pairs: number) => {
    const texture = makeTexture(
      (g, image) => {
        g.fillStyle = '#909090'
        g.fillRect(0, 0, image.width, image.height)
        const widthPerPair = image.width / pairs
        for (let i = 0; i < pairs; i++) {
          const gradient = g.createLinearGradient(i * widthPerPair, 0, (i + 1) * widthPerPair, 0)
          gradient.addColorStop(0, '#2a2a2a')
          gradient.addColorStop(0.5, '#e8e8e8')
          gradient.addColorStop(1, '#2a2a2a')
          g.fillStyle = gradient
          g.fillRect(i * widthPerPair, 0, widthPerPair, image.height)
        }
      },
      256,
      64,
      false,
    )
    texture.wrapS = texture.wrapT = THREE.RepeatWrapping
    return texture
  }
  const knurlFine = makeKnurl(20)
  knurlFine.repeat.set(8, 1)
  const knurlDial = makeKnurl(16)
  knurlDial.repeat.set(6, 1)

  const dialFace = makeTexture((g, image) => {
    const size = image.width
    const gradient = g.createRadialGradient(size / 2, size / 2, 30, size / 2, size / 2, size / 2)
    gradient.addColorStop(0, '#f0f0f0')
    gradient.addColorStop(0.75, '#d0d0d2')
    gradient.addColorStop(1, '#9fa0a4')
    g.fillStyle = gradient
    g.fillRect(0, 0, size, size)
    g.translate(size / 2, size / 2)
    g.font = 'bold 30px Arial'
    g.textAlign = 'center'
    g.textBaseline = 'middle'
    const values = ['1000', '500', '250', '125', '60', '30', '15', '8', '4', '2', '1', 'B']
    values.forEach((value, index) => {
      g.save()
      g.rotate((index / values.length) * Math.PI * 2)
      g.fillStyle = value === 'B' ? '#e07800' : '#222'
      g.fillText(value, 0, -186)
      g.strokeStyle = '#555'
      g.lineWidth = 4
      g.beginPath()
      g.moveTo(0, -146)
      g.lineTo(0, -160)
      g.stroke()
      g.restore()
    })
    g.setTransform(1, 0, 0, 1, 0, 0)
    g.fillStyle = '#8a8a8e'
    g.beginPath()
    g.arc(size / 2, size / 2, 14, 0, Math.PI * 2)
    g.fill()
  }, 512)

  const bandTexture = (items: string[], colors: string[], width: number, font: string) => {
    const texture = makeTexture(
      (g, image) => {
        g.fillStyle = '#141416'
        g.fillRect(0, 0, image.width, image.height)
        g.font = font
        g.textAlign = 'center'
        g.textBaseline = 'middle'
        const widths = items.map((item) => g.measureText(item).width)
        const gap =
          (image.width - widths.reduce((sum, itemWidth) => sum + itemWidth, 0)) / items.length
        let x = gap / 2
        items.forEach((item, index) => {
          g.fillStyle = colors[index] || '#f0f0f0'
          g.fillText(item, x + widths[index]! / 2, 68)
          x += widths[index]! + gap
        })
      },
      width,
      128,
    )
    texture.wrapS = THREE.RepeatWrapping
    return texture
  }

  const frontRingText = makeTexture((g, image) => {
    const size = image.width
    g.fillStyle = '#101010'
    g.fillRect(0, 0, size, size)
    g.translate(size / 2, size / 2)
    g.font = '600 64px Arial'
    g.textAlign = 'center'
    g.textBaseline = 'middle'
    const tokens = [
      { text: 'MAYSPUS', color: '#f5f5f5' },
      { text: '   •   ', color: '#8a8a8a' },
      { text: 'OM-SYSTEM', color: '#f5f5f5' },
      { text: '   •   ', color: '#8a8a8a' },
      { text: 'G.ZUIKO AUTO-W 1:2.8', color: '#f5f5f5' },
      { text: '   •   ', color: '#8a8a8a' },
      { text: '28mm', color: '#ff8c1e' },
    ]
    const characters = tokens.flatMap((token) =>
      [...token.text].map((character) => ({ character, color: token.color })),
    )
    const radius = 430
    const gap = 14
    const widths = characters.map(({ character }) => g.measureText(character).width)
    const total =
      widths.reduce((sum, itemWidth) => sum + itemWidth, 0) + gap * (characters.length - 1)
    let angle = -total / radius / 2
    characters.forEach(({ character, color }, index) => {
      g.save()
      g.rotate(angle + widths[index]! / 2 / radius)
      g.fillStyle = color
      g.fillText(character, 0, -radius)
      g.restore()
      angle += widths[index]! / radius + gap / radius
    })
  }, 1024)

  const letterTexture = makeTexture((g) => {
    g.font = 'bold 92px Arial'
    g.textAlign = 'center'
    g.textBaseline = 'middle'
    g.fillStyle = '#c62828'
    g.fillText('R', 64, 70)
  }, 128)

  const chromeMat = new THREE.MeshStandardMaterial({
    color: 0xe9e9ec,
    metalness: 1,
    roughness: 0.16,
  })
  const silverMat = new THREE.MeshStandardMaterial({
    color: 0xd6d6da,
    metalness: 0.95,
    roughness: 0.3,
  })
  const blackMat = new THREE.MeshStandardMaterial({
    color: 0x141414,
    metalness: 0.35,
    roughness: 0.5,
  })
  const leatherMat = new THREE.MeshStandardMaterial({
    color: 0x1e1e1e,
    roughness: 0.9,
    metalness: 0.05,
    bumpMap: leatherBump,
    bumpScale: 0.35,
  })
  const focusMat = new THREE.MeshStandardMaterial({
    color: 0x141414,
    roughness: 0.75,
    metalness: 0.2,
    bumpMap: knurlFine,
    bumpScale: 0.4,
  })
  const dialKnurl = new THREE.MeshStandardMaterial({
    color: 0xdadade,
    metalness: 0.95,
    roughness: 0.32,
    bumpMap: knurlDial,
    bumpScale: 0.35,
  })
  const dialFaceMat = new THREE.MeshStandardMaterial({
    map: dialFace,
    metalness: 0.7,
    roughness: 0.35,
  })
  const apertureMat = new THREE.MeshStandardMaterial({
    metalness: 0.4,
    roughness: 0.45,
    map: bandTexture(
      ['A', '2', '2.8', '4', '5.6', '8', '11', '16'],
      ['#ff8c1e', '#f0f0f0', '#f0f0f0', '#f0f0f0', '#f0f0f0', '#f0f0f0', '#f0f0f0', '#f0f0f0'],
      2048,
      '600 64px Arial',
    ),
  })
  const distanceMat = new THREE.MeshStandardMaterial({
    metalness: 0.5,
    roughness: 0.4,
    map: bandTexture(
      ['1', '1.5', '2', '3', '5', '10', '15', '∞', 'm'],
      [
        '#26262a',
        '#26262a',
        '#26262a',
        '#26262a',
        '#26262a',
        '#26262a',
        '#26262a',
        '#ff8c1e',
        '#26262a',
      ],
      4096,
      '600 62px Arial',
    ),
  })
  const barrelMat = new THREE.MeshStandardMaterial({
    color: 0x0e0e10,
    metalness: 0.5,
    roughness: 0.55,
  })
  const frontTextMat = new THREE.MeshStandardMaterial({
    metalness: 0.55,
    roughness: 0.4,
    map: frontRingText,
  })
  const glassDeep = new THREE.MeshPhysicalMaterial({
    color: 0x03040a,
    roughness: 0.15,
    metalness: 0.2,
    clearcoat: 1,
    clearcoatRoughness: 0.1,
    envMapIntensity: 1.4,
  })
  const glassInner = new THREE.MeshPhysicalMaterial({
    color: 0x2a3560,
    roughness: 0.05,
    metalness: 0.6,
    clearcoat: 1,
    envMapIntensity: 1.5,
  })

  const modelRoot = new THREE.Group()
  const object = new THREE.Group()
  turntable = modelRoot
  world.add(modelRoot)
  modelRoot.add(object)
  const prep = <T extends Three.Mesh>(mesh: T) => {
    mesh.castShadow = true
    mesh.receiveShadow = true
    object.add(mesh)
    return mesh
  }
  const ycyl = (
    radius: number,
    length: number,
    material: Three.Material | Three.Material[],
    x: number,
    y: number,
    z: number,
    segments = 72,
  ) => {
    const mesh = new THREE.Mesh(
      new THREE.CylinderGeometry(radius, radius, length, segments),
      material,
    )
    mesh.position.set(x, y, z)
    return prep(mesh)
  }
  const zcyl = (
    radius: number,
    length: number,
    material: Three.Material,
    x: number,
    y: number,
    z: number,
    open = false,
    segments = 72,
  ) => {
    const mesh = new THREE.Mesh(
      new THREE.CylinderGeometry(radius, radius, length, segments, 1, open),
      material,
    )
    mesh.rotation.x = Math.PI / 2
    mesh.position.set(x, y, z)
    return prep(mesh)
  }
  const box = (
    w: number,
    h: number,
    d: number,
    material: Three.Material,
    x: number,
    y: number,
    z: number,
    ry = 0,
  ) => {
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), material)
    mesh.position.set(x, y, z)
    mesh.rotation.y = ry
    return prep(mesh)
  }
  const circle = (
    radius: number,
    material: Three.Material,
    x: number,
    y: number,
    z: number,
    rx = 0,
  ) => {
    const mesh = new THREE.Mesh(new THREE.CircleGeometry(radius, 96), material)
    mesh.position.set(x, y, z)
    mesh.rotation.x = rx
    return prep(mesh)
  }
  const torus = (
    radius: number,
    tube: number,
    material: Three.Material,
    x: number,
    y: number,
    z: number,
  ) => {
    const mesh = new THREE.Mesh(new THREE.TorusGeometry(radius, tube, 24, 96), material)
    mesh.position.set(x, y, z)
    return prep(mesh)
  }

  prep(new THREE.Mesh(new RoundedBoxGeometry(3.4, 0.26, 1.34, 4, 0.05), silverMat)).position.y =
    0.13
  prep(new THREE.Mesh(new RoundedBoxGeometry(3.42, 1.16, 1.3, 3, 0.05), blackMat)).position.y = 0.84
  prep(new THREE.Mesh(new RoundedBoxGeometry(3.3, 1.1, 1.26, 3, 0.04), leatherMat)).position.y =
    0.84
  prep(new THREE.Mesh(new RoundedBoxGeometry(3.4, 0.34, 1.34, 4, 0.05), silverMat)).position.y =
    1.55

  const prismShape = new THREE.Shape()
  prismShape.moveTo(-0.6, 0)
  prismShape.lineTo(0.6, 0)
  prismShape.lineTo(0.33, 0.78)
  prismShape.lineTo(-0.33, 0.78)
  prismShape.closePath()
  const prism = new THREE.Mesh(
    new THREE.ExtrudeGeometry(prismShape, {
      depth: 1.3,
      bevelEnabled: true,
      bevelThickness: 0.06,
      bevelSize: 0.06,
      bevelSegments: 4,
    }),
    silverMat,
  )
  prism.rotation.y = -Math.PI / 2
  prism.position.set(0.65, 1.7, 0)
  prep(prism)
  const brandTexture = makeTexture(
    (g) => {
      g.font = '600 130px Arial'
      g.textAlign = 'center'
      g.textBaseline = 'middle'
      g.fillStyle = '#26262a'
      const text = 'MAYSPUS'
      const letterSpacing = 18
      const widths = [...text].map((character) => g.measureText(character).width)
      let x =
        512 -
        (widths.reduce((sum, itemWidth) => sum + itemWidth, 0) +
          letterSpacing * (text.length - 1)) /
          2
      ;[...text].forEach((character, index) => {
        g.fillText(character, x + widths[index]! / 2, 132)
        x += widths[index]! + letterSpacing
      })
    },
    1024,
    256,
  )
  const brand = new THREE.Mesh(
    new THREE.PlaneGeometry(0.9, 0.225),
    new THREE.MeshStandardMaterial({
      map: brandTexture,
      transparent: true,
      metalness: 0.4,
      roughness: 0.5,
    }),
  )
  brand.position.set(0, 2.064, 0.541)
  brand.rotation.x = -0.311
  prep(brand)
  box(0.56, 0.05, 0.64, chromeMat, 0, 2.565, 0)
  box(0.1, 0.07, 0.7, chromeMat, -0.32, 2.6, 0)
  box(0.1, 0.07, 0.7, chromeMat, 0.32, 2.6, 0)
  box(0.12, 0.05, 0.14, blackMat, 0, 2.585, 0)
  ycyl(0.3, 0.1, chromeMat, -1.28, 1.77, -0.28)
  ycyl(0.34, 0.3, [dialKnurl, chromeMat, chromeMat], -1.28, 1.96, -0.28)
  circle(0.345, dialFaceMat, -1.28, 2.112, -0.28, -Math.PI / 2)
  ycyl(0.2, 0.07, chromeMat, -1.4, 1.755, 0.28)
  box(0.52, 0.035, 0.13, silverMat, -1.12, 1.795, 0.24, -0.18)
  box(0.14, 0.05, 0.15, blackMat, -0.845, 1.795, 0.295, -0.18)
  ycyl(0.075, 0.06, chromeMat, -1.4, 1.82, 0.28)
  ycyl(0.05, 0.05, chromeMat, -1.4, 1.875, 0.28)
  ycyl(0.27, 0.3, [dialKnurl, chromeMat, chromeMat], 1.15, 1.87, 0)
  ycyl(0.24, 0.05, chromeMat, 1.15, 2.045, 0)
  box(0.05, 0.025, 0.26, chromeMat, 1.15, 2.085, 0.05, 0.5)
  const rDecal = new THREE.Mesh(
    new THREE.PlaneGeometry(0.14, 0.14),
    new THREE.MeshStandardMaterial({ map: letterTexture, transparent: true }),
  )
  rDecal.position.set(0.55, 1.62, 0.6705)
  prep(rDecal)
  ycyl(0.085, 0.07, silverMat, -0.8, 0.62, 0.66)
  const lever = new THREE.Mesh(new THREE.BoxGeometry(0.045, 0.42, 0.03), silverMat)
  lever.position.set(-0.8, 0.44, 0.71)
  lever.rotation.x = 0.5
  prep(lever)
  for (const side of [-1, 1]) {
    const lug = new THREE.Mesh(new THREE.CylinderGeometry(0.055, 0.055, 0.12, 24), chromeMat)
    lug.rotation.z = Math.PI / 2
    lug.position.set(side * 1.71, 1.5, 0)
    prep(lug)
    torus(0.15, 0.02, chromeMat, side * 1.8, 1.42, 0)
  }
  const eye = new THREE.Group()
  eye.position.set(0, 2.02, -0.55)
  const eyeRing = new THREE.Mesh(new THREE.TorusGeometry(0.14, 0.028, 20, 48), chromeMat)
  const eyeGlass = new THREE.Mesh(new THREE.CircleGeometry(0.12, 48), glassDeep)
  eyeGlass.position.z = -0.005
  eye.add(eyeRing, eyeGlass)
  eye.lookAt(0, 2.326, -1.502)
  eye.traverse((child) => {
    if (child instanceof THREE.Mesh) child.castShadow = child.receiveShadow = true
  })
  object.add(eye)

  const lensY = 1.12
  circle(0.58, new THREE.MeshStandardMaterial({ color: 0x0a0a0a, roughness: 0.8 }), 0, lensY, 0.676)
  zcyl(0.56, 0.1, chromeMat, 0, lensY, 0.72, true)
  zcyl(0.585, 0.05, silverMat, 0, lensY, 0.795, true)
  zcyl(0.62, 0.22, apertureMat, 0, lensY, 0.93, true)
  box(0.05, 0.04, 0.07, silverMat, 0.44, 1.56, 0.93)
  zcyl(0.68, 0.56, focusMat, 0, lensY, 1.32, true)
  zcyl(0.67, 0.09, distanceMat, 0, lensY, 1.645, true)
  zcyl(0.64, 0.16, barrelMat, 0, lensY, 1.78, true)
  const ring = new THREE.Mesh(new THREE.RingGeometry(0.39, 0.56, 128), frontTextMat)
  ring.position.set(0, lensY, 1.862)
  prep(ring)
  torus(0.56, 0.02, chromeMat, 0, lensY, 1.865)
  circle(0.52, glassDeep, 0, lensY, 1.8)
  circle(0.3, glassInner, 0, lensY, 1.848)
  torus(0.305, 0.007, blackMat, 0, lensY, 1.851)

  const sun = new THREE.DirectionalLight(0xffffff, 2)
  sun.position.set(5, 9, 4)
  sun.castShadow = true
  sun.shadow.mapSize.set(1024, 1024)
  Object.assign(sun.shadow.camera, { left: -6, right: 6, top: 6, bottom: -6, near: 1, far: 25 })
  sun.shadow.bias = -0.0004
  sun.shadow.normalBias = 0.02
  world.add(sun)
  const fill = new THREE.DirectionalLight(0xffffff, 0.5)
  fill.position.set(-6, 4, -5)
  world.add(fill)

  const resize = () => {
    if (!renderer || disposed) return
    const nextWidth = Math.max(1, host.clientWidth)
    const nextHeight = Math.max(1, host.clientHeight)
    if (nextWidth === drawWidth && nextHeight === drawHeight) return
    drawWidth = nextWidth
    drawHeight = nextHeight
    camera.aspect = nextWidth / nextHeight
    camera.updateProjectionMatrix()
    renderer.setSize(nextWidth, nextHeight, false)
    if (prefersReducedMotion.matches) renderOnce()
  }
  drawWidth = width
  drawHeight = height
  resizeObserver = new ResizeObserver(resize)
  resizeObserver.observe(host)

  const pointerDown = (event: PointerEvent) => {
    if (event.button !== 0 || !turntable) return
    event.preventDefault()
    window.clearTimeout(releaseTimer)
    returnTween?.kill()
    mode = 'dragging'
    downX = event.clientX
    downY = event.clientY
    viewerCanvas.setPointerCapture(event.pointerId)
    viewerCanvas.classList.add('is-dragging')
  }
  const pointerMove = (event: PointerEvent) => {
    if (mode !== 'dragging' || !turntable) return
    const deltaX = event.clientX - downX
    const deltaY = event.clientY - downY
    downX = event.clientX
    downY = event.clientY
    turntable.rotation.y += deltaX * 0.009
    cameraPitch = clamp(cameraPitch + deltaY * 0.006, 0.62, 2.36)
    updateCameraPitch()
    if (prefersReducedMotion.matches) renderOnce()
  }
  const pointerUp = (event: PointerEvent) => {
    if (mode !== 'dragging' || !turntable) return
    mode = 'returning'
    if (viewerCanvas.hasPointerCapture(event.pointerId))
      viewerCanvas.releasePointerCapture(event.pointerId)
    viewerCanvas.classList.remove('is-dragging')
    if (prefersReducedMotion.matches) {
      mode = 'idle'
      return
    }
    releaseTimer = window.setTimeout(() => {
      if (disposed || mode !== 'returning' || !turntable) return
      const fullTurn = Math.PI * 2
      const restY =
        turntable.rotation.y -
        (((((turntable.rotation.y + Math.PI) % fullTurn) + fullTurn) % fullTurn) - Math.PI)
      const current = { y: turntable.rotation.y, phi: cameraPitch }
      returnTween = gsap.to(current, {
        y: restY,
        phi: cameraRestPitch,
        duration: 1.2,
        ease: 'power3.inOut',
        onUpdate: () => {
          if (!turntable) return
          turntable.rotation.y = current.y
          cameraPitch = current.phi
          updateCameraPitch()
        },
        onComplete: () => {
          mode = 'idle'
        },
      })
    }, 220)
  }
  const pointerCancel = (event: PointerEvent) => pointerUp(event)
  const keyDown = (event: KeyboardEvent) => {
    if (!turntable) return
    const turnStep = 0.16
    const tiltStep = 0.08
    if (event.key === 'ArrowLeft') turntable.rotation.y -= turnStep
    else if (event.key === 'ArrowRight') turntable.rotation.y += turnStep
    else if (event.key === 'ArrowUp') cameraPitch = clamp(cameraPitch - tiltStep, 0.62, 2.36)
    else if (event.key === 'ArrowDown') cameraPitch = clamp(cameraPitch + tiltStep, 0.62, 2.36)
    else return
    event.preventDefault()
    returnTween?.kill()
    mode = 'idle'
    updateCameraPitch()
    if (prefersReducedMotion.matches) renderOnce()
  }

  viewerCanvas.addEventListener('pointerdown', pointerDown)
  viewerCanvas.addEventListener('pointermove', pointerMove)
  viewerCanvas.addEventListener('pointerup', pointerUp)
  viewerCanvas.addEventListener('pointercancel', pointerCancel)
  viewerCanvas.addEventListener('lostpointercapture', pointerUp)
  viewerCanvas.addEventListener('keydown', keyDown)
  removePointerHandlers = () => {
    viewerCanvas.removeEventListener('pointerdown', pointerDown)
    viewerCanvas.removeEventListener('pointermove', pointerMove)
    viewerCanvas.removeEventListener('pointerup', pointerUp)
    viewerCanvas.removeEventListener('pointercancel', pointerCancel)
    viewerCanvas.removeEventListener('lostpointercapture', pointerUp)
    viewerCanvas.removeEventListener('keydown', keyDown)
  }

  let renderFailed = false
  renderOnce = () => {
    if (disposed || renderFailed) return
    try {
      webgl.render(world, camera)
    } catch (error) {
      renderFailed = true
      viewerUnavailable = true
      window.clearTimeout(failedTimer)
      console.error('[Lendora] Camera viewer render failed:', error)
      loading.value = false
      failed.value = true
      emit('error', error)
      disposeViewer()
      return
    }
    if (loading.value) {
      loading.value = false
      window.clearTimeout(failedTimer)
      emit('ready')
    }
  }

  let lastTime = 0
  const animate = (time: number) => {
    if (disposed || renderFailed || !turntable) return
    const delta = lastTime ? Math.min((time - lastTime) / 1000, 0.05) : 0
    lastTime = time
    if (mode === 'idle' && !prefersReducedMotion.matches) turntable.rotation.y += delta * 0.035
    renderOnce()
  }
  const applyMotionPreference = () => {
    if (prefersReducedMotion.matches) {
      returnTween?.kill()
      if (mode === 'returning') mode = 'idle'
      webgl.setAnimationLoop(null)
      renderOnce()
    } else {
      lastTime = 0
      webgl.setAnimationLoop(animate)
    }
  }
  prefersReducedMotion.addEventListener('change', applyMotionPreference)
  removeMotionPreference = () =>
    prefersReducedMotion.removeEventListener('change', applyMotionPreference)
  applyMotionPreference()
}

function disposeObject(root: Three.Scene) {
  const THREE = threeRuntime
  if (!THREE) return
  const geometries = new Set<Three.BufferGeometry>()
  const materials = new Set<Three.Material>()
  const textures = new Set<Three.Texture>()
  root.traverse((object) => {
    if (!(object instanceof THREE.Mesh)) return
    geometries.add(object.geometry)
    const meshMaterials = Array.isArray(object.material) ? object.material : [object.material]
    meshMaterials.forEach((material) => {
      materials.add(material)
      Object.values(material).forEach((value) => {
        if (value instanceof THREE.Texture) textures.add(value)
      })
    })
  })
  textures.forEach((texture) => texture.dispose())
  materials.forEach((material) => material.dispose())
  geometries.forEach((geometry) => geometry.dispose())
}

function disposeViewer() {
  window.clearTimeout(releaseTimer)
  returnTween?.kill()
  resizeObserver?.disconnect()
  resizeObserver = undefined
  removePointerHandlers?.()
  removePointerHandlers = undefined
  removeMotionPreference?.()
  removeMotionPreference = undefined
  renderer?.setAnimationLoop(null)
  if (scene) disposeObject(scene)
  environmentTarget?.dispose()
  environmentTarget = undefined
  renderer?.dispose()
  renderer?.domElement.remove()
  renderer = undefined
  scene = undefined
  turntable = undefined
  threeRuntime = undefined
}

function startViewer() {
  if (started || disposed || !props.active) return
  started = true
  const host = stage.value
  if (!host) return
  failedTimer = window.setTimeout(() => {
    if (loading.value && !disposed) {
      const error = new Error('3D camera did not render within 8 seconds.')
      console.error('[Lendora] Camera viewer timed out:', error)
      viewerUnavailable = true
      disposeViewer()
      loading.value = false
      failed.value = true
      emit('error', error)
    }
  }, 8000)
  void createCameraScene(host).catch((error: unknown) => {
    if (disposed || viewerUnavailable) return
    viewerUnavailable = true
    window.clearTimeout(failedTimer)
    console.error('[Lendora] Camera viewer initialization failed:', error)
    disposeViewer()
    loading.value = false
    failed.value = true
    emit('error', error)
  })
}

onMounted(startViewer)
watch(() => props.active, startViewer)

onUnmounted(() => {
  disposed = true
  window.clearTimeout(failedTimer)
  window.clearTimeout(releaseTimer)
  returnTween?.kill()
  disposeViewer()
})
</script>

<template>
  <div
    ref="stage"
    class="camera-viewer"
    :class="{ 'is-loading': loading }"
    role="group"
    aria-label="Kamera vintage 3D interaktif"
  >
    <div v-if="loading" class="camera-status" role="status" aria-live="polite">
      Menyiapkan model 3D…
    </div>
    <div v-if="failed" class="camera-status camera-status-error" role="status">
      Model 3D tidak tersedia. Konten halaman tetap dapat digunakan.
    </div>
    <div v-if="!failed" class="camera-hint" aria-hidden="true">
      <span class="camera-hint-mark">↔</span> SERET UNTUK MEMUTAR
    </div>
  </div>
</template>
