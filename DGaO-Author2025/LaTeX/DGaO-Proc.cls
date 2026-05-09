%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%
%
% LaTeX package for submissions to DGaO proceedings
% (http://www.dgao-proceedings.de)
%
% VERSION: 1.0 (2006/09/17)
%
% written by J. Kaminski [JNK] <jkaminski@optik.uni-erlangen.de>
%
% CHANGES:
%
% - 07/06/27 [JNK] : URL in the footer should be blue to match Word template
% - 07/06/27 [JNK] : The first parameter of \columnfigure now defaults to \columnwidth	 
% - 07/06/28 [JNK] : Fixed problem with german package
%
%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%

% Thanks for looking at my source code ;-)

\NeedsTeXFormat{LaTeX2e}
\ProvidesClass{DGaO-Proc}[2007/06/28 LaTeX package for DGaO proceedings]
\typeout{***DGaO Proceedings document class***}

\newcommand\s@URL{http://www.dgao-proceedings.de}
\newcommand\s@ISSN{1614-8436}
\newcommand\s@URN{urn:nbn:de:0287-2025-XXXX-Y}

\RequirePackage{ifthen}

% option flags
\newboolean{@german}\setboolean{@german}{false}
\newboolean{@notc}\setboolean{@notc}{false}

% check if we produce pdf output
\newif\if@pdf\@pdffalse
\ifx\pdfoutput\undefined
  \@pdffalse 
\else 
  \ifnum\pdfoutput=0
    \@pdffalse
  \else
    \ifnum\pdfoutput=1
    	\@pdftrue
    \fi
  \fi
\fi
\newcommand{\ifpdf}[2]{\if@pdf#1\else#2\fi}

% declare options here...
\DeclareOption{german}{\setboolean{@german}{true}}
\DeclareOption{notcfonts}{\setboolean{@notc}{true}}

% load default class and options
\DeclareOption*{\PassOptionsToClass{\CurrentOption}{article}}
\ExecuteOptions{a4paper,10pt,oneside}
\ProcessOptions\relax
\LoadClass{article}

% load default packages
\RequirePackage{calc}
\RequirePackage{multicol}
\RequirePackage{fancyhdr}

% include graphics
\ifpdf{
	\RequirePackage[pdftex]{graphicx}
	\RequirePackage[pdftex]{color}
	\pdfcompresslevel=9
	\pdfpagewidth=210mm
	\pdfpageheight=297mm % why isn't this done automatically?
}{
	\RequirePackage{graphicx}
	\RequirePackage[dvips]{color}
}

\renewcommand{\familydefault}{\sfdefault}
\RequirePackage{helvet} % use Helvetica if possible

\ifthenelse{\boolean{@notc}}{%
	\newcommand{\um}{\ensuremath{\,\mu}m} % no TC enconding: use math mu
	}{%
	\RequirePackage[TS1,T1]{fontenc} % use TC font encoding
	\DeclareTextSymbolDefault{\textmu}{TS1}
	\newcommand{\um}{\ensuremath{\mbox{\,\textmu m}}}
	}

% german stuff
\ifthenelse{\boolean{@german}}{%
	\RequirePackage[ngerman]{babel}
	\RequirePackage[latin1]{inputenc}
	\addto\captionsngerman{%
		\renewcommand{\figurename}{Abb.}
		\renewcommand{\tablename}{Tab.}
		}
	}{%
	\renewcommand{\figurename}{Fig.}
	\renewcommand{\tablename}{Tab.}
	}

% some goodies
\newcommand{\superscript}[1]{\ensuremath{^\textsf{#1}}}
\newcommand{\subscript}[1]{\ensuremath{_\textsf{#1}}}
\newcommand{\permil}{%
	\ensuremath{%
    \kern-.05em%
    \raise.5ex\hbox{\the\scriptfont0 0}%
    \kern-.15em/\kern-.15em%
    \lower.25ex\hbox{\the\scriptfont0 00}
    }
   }
\newcommand{\na}{%
	\ensuremath{\textsf{\textsl N}\kern-0.18em\textsf{\textsl A}}
	}

% title definition
\newcommand{\s@title}{}
\renewcommand{\title}[1]{\renewcommand{\s@title}{#1}}
\newcommand{\s@author}{}
\renewcommand{\author}[1]{\renewcommand{\s@author}{#1}}
\newcommand{\s@affiliation}{}
\newcommand{\affiliation}[1]{\renewcommand{\s@affiliation}{#1}}
\newcommand{\s@contact}{}
\newcommand{\contactmail}[1]{\renewcommand{\s@contact}{#1}}
\newcommand{\s@abstract}{}
\renewcommand{\abstract}[1]{\renewcommand{\s@abstract}{#1}}
\newcommand{\s@year}{}
\renewcommand{\year}[1]{\renewcommand{\s@year}{#1}}

\renewcommand{\maketitle}{%
	\begin{center}
	\textbf{\large\s@title}\\[4.2mm]
	\s@author\\[1.2mm]
	\textit{\s@affiliation}\\[1.4mm]
	\textit{mailto:\s@contact}\\[5mm]
	\begin{minipage}{12.6cm}
	\s@abstract
	\end{minipage}
	\end{center}
	\vspace{2.3mm}
	}

% page layout
\thispagestyle{fancy}
\setlength{\parindent}{\z@}
\setlength{\parskip}{5pt plus 2pt minus 1pt}

\setlength{\textwidth}{169mm}
\setlength{\textheight}{245mm}
\setlength{\columnsep}{8mm}
\setlength{\topmargin}{-14mm}
\setlength{\footskip}{15mm}
\setlength{\oddsidemargin}{-5mm}
\frenchspacing

% footer
\fancyfoot[C]{\footnotesize{DGaO Proceedings~\s@year~--~\textit{\color[rgb]{0,0,0.6}\s@URL}~--~ISSN:~\s@ISSN}~--~\s@URN\\ submitted: XX.XXX.2025~--~published: XX.XXX.2025}
\fancyhead[]{} % no header
\renewcommand{\headrulewidth}{\z@}
\renewcommand{\footrulewidth}{\z@}

% headings
\renewcommand{\section}{%
	\@startsection{section}{1}{\z@}{3mm}{1mm}{\textbf}%
	}
	
\renewcommand{\subsection}{%
	\@startsection{subsection}{2}{\z@}{0mm}{0.1mm}{\textit}%
	}

% begin and end
\AtBeginDocument{%
 \maketitle
 \pagestyle{fancy}
 \begin{multicols}{2}
 }
\AtEndDocument{%
	\end{multicols}
	}

% figures
\newcommand{\columnfigure}[3][\columnwidth]{%
	\refstepcounter{figure}
	\label{fig:#2}
	\vspace{1ex}
	\begin{minipage}{\columnwidth}
	\begin{center}
	\includegraphics[width=#1]{#2}\\
	\end{center}
	\small\textit{\textbf{\figurename~\thefigure}~#3\/}
	\vspace{1ex}
	\end{minipage}
	}

% tables
\newenvironment{columntable}[3]{%
	\newcommand{\s@tablecaption}{#2}
	\refstepcounter{table}
	\label{tab:#3}
	\vspace{1ex}
	\begin{minipage}{\columnwidth}
	\small
	\begin{center}
	\begin{tabular*}{\columnwidth}{@{\extracolsep{\fill}}#1}
  }{%
  \end{tabular*}
  \end{center}
  \small\textit{\textbf{\tablename~\thetable}~\s@tablecaption\/}
  \vspace{1ex}
  \end{minipage}
  }

% pre-defined abbreviations
% These are not used by default, because there may be some
% name clash with user-defined commands

\newcommand\dgao{DGaO}

%\newcommand\ao{Appl.\ Opt.}
%\newcommand\ap{Appl.\  Phys.}
%\newcommand\apa{Appl.\  Phys.\ A}
%\newcommand\apb{Appl.\  Phys.\ B}
%\newcommand\apl{Appl.\ Phys.\ Lett.}
%\newcommand\apj{Astrophys.\ J.}
%\newcommand\bell{Bell Syst.\ Tech.\ J.}
%\newcommand\iovs{Invest.\ Ophthalmol.\ Visual\ Sci.}
%\newcommand\jcp{J.\ Chem.\ Phys.}
%\newcommand\jmo{J.\ Mod.\ Opt.}
%\newcommand\jon{J.\ Opt.\ Netw.}
%\newcommand\josa{J.\ Opt.\ Soc.\ Am.}
%\newcommand\josaa{J.\ Opt.\ Soc.\ Am.\ A}
%\newcommand\josab{J.\ Opt.\ Soc.\ Am.\ B}
%\newcommand\jpp{J.\ Phys.\ (Paris)}
%\newcommand\jqe{IEEE J.\ Quantum Electron.}
%\newcommand\mtt{IEEE Trans.\ Microwave Theory Tech.}
%\newcommand\nat{Nature (London)}
%\newcommand\oc{Opt.\ Commun.}
%\newcommand\oeng{Opt.\ Eng.}
%\newcommand\ol{Opt.\ Lett.}
%\newcommand\op{Optik}
%\newcommand\opex{Opt.\ Express}
%\newcommand\orv{Opt.\ Rev.}
%\newcommand\pao{Pure Appl.\ Opt.}
%\newcommand\pdgao{Proc.\ DGaO}
%\newcommand\pl{Phys.\ Lett.}
%\newcommand\pra{Phys.\ Rev.\ A}
%\newcommand\prb{Phys.\ Rev.\ B}
%\newcommand\prc{Phys.\ Rev.\ C}
%\newcommand\prd{Phys.\ Rev.\ D}
%\newcommand\pre{Phys.\ Rev.\ E}
%\newcommand\prl{Phys.\ Rev.\ Lett.}
%\newcommand\pspie{Proc.\ SPIE}
%\newcommand\rmp{Rev.\ Mod.\ Phys.}
%\newcommand\sjqe{Sov.\ J.\ Quantum Electron.}
%\newcommand\vr{Vision Res.}
%\newcommand\cleo{Conference on Lasers and Electro-Optics}
%\newcommand\assl{Advanced Solid State Lasers}
%\newcommand\tops{Trends in Optics and Photonics} 

\endinput

%%% Local Variables: 
%%% mode: latex
%%% TeX-master: t
%%% End: 